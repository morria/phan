<?php

declare(strict_types=1);

namespace Phan\Language;

use Closure;
use Generator;
use InvalidArgumentException;
use Phan\CodeBase;
use Phan\Config;
use Phan\Debug\Frame;
use Phan\Exception\CodeBaseException;
use Phan\Exception\IssueException;
use Phan\Exception\RecursionDepthException;
use Phan\Issue;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Element\Method;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\FQSEN\FullyQualifiedFunctionName;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\Type\ArrayShapeType;
use Phan\Language\Type\ArrayType;
use Phan\Language\Type\AssociativeArrayType;
use Phan\Language\Type\BoolType;
use Phan\Language\Type\CallableStringType;
use Phan\Language\Type\CallableType;
use Phan\Language\Type\ClassStringType;
use Phan\Language\Type\FalseType;
use Phan\Language\Type\FloatType;
use Phan\Language\Type\GenericArrayInterface;
use Phan\Language\Type\GenericArrayType;
use Phan\Language\Type\IntersectionType;
use Phan\Language\Type\IntType;
use Phan\Language\Type\IterableType;
use Phan\Language\Type\ListType;
use Phan\Language\Type\LiteralFloatType;
use Phan\Language\Type\LiteralIntType;
use Phan\Language\Type\LiteralStringType;
use Phan\Language\Type\LiteralTypeInterface;
use Phan\Language\Type\MixedType;
use Phan\Language\Type\MultiType;
use Phan\Language\Type\NeverType;
use Phan\Language\Type\NonEmptyArrayInterface;
use Phan\Language\Type\NonEmptyAssociativeArrayType;
use Phan\Language\Type\NonEmptyListType;
use Phan\Language\Type\NonEmptyMixedType;
use Phan\Language\Type\NonEmptyStringType;
use Phan\Language\Type\NullType;
use Phan\Language\Type\ObjectType;
use Phan\Language\Type\ScalarRawType;
use Phan\Language\Type\ScalarType;
use Phan\Language\Type\SelfType;
use Phan\Language\Type\StaticType;
use Phan\Language\Type\StringType;
use Phan\Language\Type\TemplateType;
use Phan\Language\Type\TrueType;
use Phan\Language\Type\VoidType;
use Serializable;
use Stringable;

use function array_push;
use function count;
use function implode;
use function in_array;
use function is_int;
use function substr;

/**
 * Phan's internal representation of union types, and methods for working with union types.
 *
 * This representation is immutable.
 * Phan represents union types as a list of unique `Type`s
 * (This was the most efficient representation, since most union types have 0, 1, or 2 unique types in practice)
 * To add/remove a type to a UnionType, you replace it with a UnionType that had that type added.
 *
 * @see AnnotatedUnionType for the way Phan represents extra information about types
 * @see https://github.com/phan/phan/wiki/About-Union-Types
 *
 * > Union types can be any native type such as int, string, bool, or array, any class such as DateTime,
 * > arrays of types such as string[], DateTime[],
 * > or a union of any other types such as string|int|null|DateTime|DateTime[],
 * > and many other types
 *
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod TODO: Document the public methods
 * @phan-pure types/union types are immutable, but technically not pure (some methods cause issues to be emitted with Issue::maybeEmit()).
 *            However, it's useful to treat them as if they were pure, to warn about not using return values.
 * @suppress PhanRedefinedInheritedInterface this uses a polyfill for Stringable
 */
class UnionType implements Serializable, Stringable
{
    /**
     * @var string
     * A list of one or more types delimited by the '|'
     * character (e.g. 'int|DateTime|string[]')
     */
    // phpcs:ignore Generic.NamingConventions.UpperCaseConstantName
    public const union_type_regex =
        Type::type_regex
        . '(\s*[|&]\s*' . Type::type_regex . ')*';

    /**
     * @var string
     * A list of one or more types delimited by the '|'
     * character (e.g. 'int|DateTime|string[]' or 'null|$this')
     * This may be used for return types.
     *
     * TODO: Equivalent variants with no capturing? (May not improve performance much)
     */
    // phpcs:ignore Generic.NamingConventions.UpperCaseConstantName
    public const union_type_regex_or_this =
        Type::type_regex_or_this
        . '(\s*[|&]\s*' . Type::type_regex_or_this . ')*';

    /**
     * @var list<Type> This is an immutable list of unique types.
     */
    private $type_set;

    /**
     * @var list<Type> * This is an immutable list of unique types.
     */
    private $real_type_set;

    /**
     * @param list<Type> $type_list
     * An optional list of types represented by this union
     * @param bool $is_unique - Whether or not this is already unique. Only set to true within UnionType code.
     * @param list<Type> $real_type_set
     * @see UnionType::of() for a more memory efficient equivalent.
     * @phan-pure
     */
    public function __construct(array $type_list, bool $is_unique = false, array $real_type_set = [])
    {
        $this->type_set = ($is_unique || \count($type_list) <= 1) ? $type_list : self::getUniqueTypes($type_list);
        $this->real_type_set = ($is_unique || \count($real_type_set) <= 1) ? $real_type_set : self::getUniqueTypes($real_type_set);
    }

    /**
     * @param Type[] $type_list
     * @param Type[] $real_type_set
     * @phan-pure
     */
    public static function of(array $type_list, array $real_type_set = []): UnionType
    {
        $n = \count($type_list);
        if ($n === 0) {
            if ($real_type_set) {
                if (\count($real_type_set) === 1) {
                    return \reset($real_type_set)->asRealUnionType();
                }
                return new self($real_type_set, false, $real_type_set);
            }
            return self::$empty_instance;
        }
        if ($n === 1 && \count($real_type_set) <= 1) {
            if (!$real_type_set) {
                // @phan-suppress-next-line PhanPossiblyNonClassMethodCall
                return \reset($type_list)->asPHPDocUnionType();
            } elseif ($real_type_set === $type_list) {
                // @phan-suppress-next-line PhanPossiblyNonClassMethodCall
                return \reset($type_list)->asRealUnionType();
            }
            return new self($type_list, true, $real_type_set);
        } else {
            return new self($type_list, false, $real_type_set);
        }
    }

    /**
     * @param Type[] $type_list
     * @param Type[] $real_type_set
     * @phan-pure
     */
    private static function ofUnique(array $type_list, array $real_type_set = []): UnionType
    {
        $n = \count($type_list);
        if ($n === 0) {
            if ($real_type_set) {
                if (\count($real_type_set) === 1) {
                    return \reset($real_type_set)->asRealUnionType();
                }
                return new self($real_type_set, true, $real_type_set);
            }
            return self::$empty_instance;
        }
        if ($n === 1 && \count($real_type_set) <= 1) {
            if (!$real_type_set) {
                // @phan-suppress-next-line PhanPossiblyNonClassMethodCall
                return \reset($type_list)->asPHPDocUnionType();
            } elseif ($real_type_set === $type_list) {
                // @phan-suppress-next-line PhanPossiblyNonClassMethodCall
                return \reset($type_list)->asRealUnionType();
            }
        }
        return new self($type_list, true, $real_type_set);
    }

    /** @var EmptyUnionType an empty union type - Cached here for quick access. */
    private static $empty_instance;

    /**
     * @return EmptyUnionType (Real return type omitted for performance)
     */
    public static function empty(): EmptyUnionType
    {
        return self::$empty_instance;
    }

    /**
     * @internal
     */
    public static function init(): void
    {
        if (\is_null(self::$empty_instance)) {
            self::$empty_instance = EmptyUnionType::instance();
        }
    }

    /**
     * @suppress PhanAccessReadOnlyProperty
     */
    public function __clone()
    {
        $this->with_erased_real_type_set = null;
        $this->as_normalized = null;
    }

    // __clone of $this->type_set would be a no-op due to copy on write semantics.
    // And clone isn't necessary anymore now that type_set is immutable

    /**
     * @param string $fully_qualified_string
     * A '|' delimited string representing a type in the form
     * 'int|string|null|ClassName'.
     * @throws InvalidArgumentException if any type name in the union type was invalid
     *
     * @see self::fromFullyQualifiedRealString() if you are absolutely sure this is the real type of the expression.
     * @phan-pure
     */
    public static function fromFullyQualifiedPHPDocString(
        string $fully_qualified_string
    ): UnionType {
        if ($fully_qualified_string === '') {
            return self::$empty_instance;
        }

        /** @var array<string,UnionType> annotation not read by phan */
        static $memoize_map = [];
        $union_type = $memoize_map[$fully_qualified_string] ?? null;

        if (\is_null($union_type)) {
            /** Convert the `|` separated types in the union type to a list of types */
            $types = \array_map(static function (string $type_name): Type {
                // @phan-suppress-next-line PhanThrowTypeAbsentForCall FIXME: Standardize on InvalidArgumentException
                return Type::fromFullyQualifiedString($type_name);
            }, self::extractTypeParts($fully_qualified_string));

            $unique_types = self::getUniqueTypes(self::normalizeMultiTypes($types));
            if (\count($unique_types) === 1) {
                // @phan-suppress-next-line PhanPossiblyNonClassMethodCall
                $union_type = \reset($unique_types)->asPHPDocUnionType();
            } else {
                // TODO: Support template types within <> and test?
                $union_type = new UnionType(
                    $unique_types,
                    true
                );
            }
            $memoize_map[$fully_qualified_string] = $union_type;
        }

        return $union_type;
    }

    /**
     * @return list<Type> the corresponding set of types for this string.
     * @throws InvalidArgumentException if any type name in the union type was invalid
     */
    public static function typeSetFromString(string $fully_qualified_string): array
    {
        return self::fromFullyQualifiedPHPDocString($fully_qualified_string)->type_set;
    }

    /**
     * Returns a type with the following phpdoc types and real types.
     * Use this if they are both non-empty but different.
     * The caller should pass in phpdoc types that are subtypes of the real types
     * (e.g. phpdoc='int|float' real='int')
     * @phan-pure
     */
    public static function fromFullyQualifiedPHPDocAndRealString(
        string $fully_qualified_phpdoc_string,
        string $fully_qualified_real_string
    ): UnionType {
        if ($fully_qualified_real_string === $fully_qualified_phpdoc_string) {
            return self::fromFullyQualifiedRealString($fully_qualified_real_string);
        }
        static $memoize_map = [];
        $key = "$fully_qualified_real_string@$fully_qualified_phpdoc_string";
        $union_type = $memoize_map[$key] ?? null;

        if (\is_null($union_type)) {
            $phpdoc = UnionType::fromFullyQualifiedPHPDocString($fully_qualified_phpdoc_string);
            $real = UnionType::fromFullyQualifiedPHPDocString($fully_qualified_real_string);
            $union_type = UnionType::ofUnique($phpdoc->getTypeSet(), $real->getTypeSet());
            $memoize_map[$key] = $union_type;
        }
        return $union_type;
    }

    /**
     * @param string $fully_qualified_string
     * A '|' delimited string representing a type in the form
     * 'int|string|null|ClassName'.
     * @throws InvalidArgumentException if any type name in the union type was invalid
     * @phan-pure
     */
    public static function fromFullyQualifiedRealString(
        string $fully_qualified_string
    ): UnionType {
        if ($fully_qualified_string === '') {
            return self::$empty_instance;
        }

        /** @var array<string,UnionType> annotation not read by phan */
        static $memoize_map = [];
        $union_type = $memoize_map[$fully_qualified_string] ?? null;

        if (\is_null($union_type)) {
            /** Convert the `|` separated types in the union type to a list of types */
            $types = \array_map(static function (string $type_name): Type {
                // @phan-suppress-next-line PhanThrowTypeAbsentForCall FIXME: Standardize on InvalidArgumentException
                return Type::fromFullyQualifiedString($type_name);
            }, self::extractTypeParts($fully_qualified_string));

            $unique_types = self::getUniqueTypes(self::normalizeMultiTypes($types));
            if (\count($unique_types) === 1) {
                // @phan-suppress-next-line PhanPossiblyNonClassMethodCall
                $union_type = \reset($unique_types)->asRealUnionType();
            } else {
                // TODO: Support template types within <> and test?
                $union_type = new UnionType(
                    $unique_types,
                    true,
                    $unique_types
                );
            }
            $memoize_map[$fully_qualified_string] = $union_type;
        }

        return $union_type;
    }

    /**
     * Returns a list of unique types in the provided type list.
     *
     * @param list<Type> $type_list
     * @return list<Type>
     * @phan-pure
     */
    public static function getUniqueTypes(array $type_list): array
    {
        $new_type_list = [];
        if (\count($type_list) >= 8) {
            // This approach is faster, but only when there are 8 or more types (tested in php 7.3)
            // See https://github.com/phan/phan/pull/3475#issuecomment-550570579
            foreach ($type_list as $type) {
                $new_type_list[\spl_object_id($type)] = $type;
            }
            return \array_values($new_type_list);
        }
        foreach ($type_list as $type) {
            if (!\in_array($type, $new_type_list, true)) {
                $new_type_list[] = $type;
            }
        }
        return $new_type_list;
    }

    /**
     * @param list<Type> $type_list a list of unique types
     * @param list<Type> $other_type_list a list of unique types
     * @return list<Type> a list of the unique types from both lists
     * @phan-pure
     */
    private static function mergeUniqueTypes(array $type_list, array $other_type_list): array
    {
        if (\count($other_type_list) <= 4) {
            // NOTE: implementing it this way takes advantage of copy-on-write for small arrays.
            // If no new types were added, the original array $type_list will be reused.
            foreach ($other_type_list as $type) {
                if (!\in_array($type, $type_list, true)) {
                    $type_list[] = $type;
                }
            }
            return $type_list;
        }
        $new_type_list = [];
        // Avoid worst-case quadratic runtime
        foreach ($type_list as $type) {
            $new_type_list[\spl_object_id($type)] = $type;
        }
        foreach ($other_type_list as $type) {
            $new_type_list[\spl_object_id($type)] = $type;
        }
        return \array_values($new_type_list);
    }

    /**
     * @param string $type_string
     * A '|' delimited string representing a type in the form
     * 'int|string|null|ClassName'.
     *
     * @param Context $context
     * The context in which the type string was
     * found
     *
     * @param int $source one of the constants in Type::FROM_*
     *
     * @param ?CodeBase $code_base
     * May be provided to resolve 'parent' in the context
     * (e.g. if parsing complex phpdoc).
     * Unnecessary in most use cases.
     * @phan-pure
     */
    public static function fromStringInContext(
        string $type_string,
        Context $context,
        int $source,
        ?CodeBase $code_base = null
    ): UnionType {
        if ($type_string === '') {
            // NOTE: '0' is a valid LiteralIntType
            return self::$empty_instance;
        }

        $types = [];
        foreach (self::extractTypePartsForStringInContext($type_string) as $type_name) {
            $types[] = Type::fromStringInContext(
                $type_name,
                $context,
                $source,
                $code_base
            );
        }
        return UnionType::of(self::normalizeMultiTypes($types));
    }

    /**
     * @return list<string>
     */
    private static function extractTypePartsForStringInContext(string $type_string): array
    {
        static $cache = [];
        $parts = $cache[$type_string] ?? null;
        if (\is_array($parts)) {
            return $parts;
        }
        $parts = [];
        foreach (self::extractTypeParts($type_string) as $type_name) {
            // Exclude empty type names
            // Exclude namespaces without type names (e.g. `\`, `\NS\`)
            if ($type_name === '' || \preg_match('@\\\\[\[\]]*$@D', $type_name)) {
                $parts[] = $type_name;
                continue;
            }
            if (substr($type_name, -1) === ')') {
                if (substr($type_name, 0, 1) === '(') {
                    // @phan-suppress-next-line PhanPossiblyFalseTypeArgument
                    foreach (self::extractTypePartsForStringInContext(substr($type_name, 1, -1)) as $inner_type_name) {
                        $parts[] = $inner_type_name;
                    }
                    continue;
                } elseif (substr($type_name, 0, 2) === '?(') {
                    // @phan-suppress-next-line PhanPossiblyFalseTypeArgument
                    foreach (self::extractTypePartsForStringInContext(substr($type_name, 2, -1)) as $inner_type_name) {
                        if (substr($inner_type_name, 0, 1) === '?') {
                            $parts[] = $inner_type_name;
                        } else {
                            $parts[] = '?' . $inner_type_name;
                        }
                    }
                    continue;
                }
            }
            $parts[] = $type_name;
        }
        $cache[$type_string] = $parts;
        return $parts;
    }

    /**
     * @return list<string>
     */
    private static function extractTypeParts(string $type_string): array
    {
        $parts = [];
        // @phan-suppress-next-line PhanTypeInvalidExpressionArrayDestructuring FIXME: Fix preg_split type inference
        foreach (\preg_split('@[|&]@', $type_string, -1, \PREG_SPLIT_OFFSET_CAPTURE) as [$part, $offset]) {
            $parts[] = new TypePart(\trim($part), $offset > 0 ? $type_string[$offset - 1] : '|');
        }

        if (\count($parts) <= 1) {
            return $parts ? [$parts[0]->type] : [];
        }
        if (!\preg_match('/[<({&]/', $type_string)) {
            return \array_map(static function (TypePart $part): string {
                return $part->type;
            }, $parts);
        }
        return self::mergeTypeParts($parts);
    }

    /**
     * Expands any GenericMultiArrayType and ScalarRawType instances in $types if necessary.
     *
     * @param list<Type> $types
     * @return array<int,Type>
     */
    public static function normalizeMultiTypes(array $types): array
    {
        foreach ($types as $i => $type) {
            if ($type instanceof MultiType) {
                foreach ($type->asIndividualTypeInstances() as $new_type) {
                    $types[] = $new_type;
                }
                unset($types[$i]);
            }
        }
        return $types;
    }

    /**
     * @param list<TypePart> $parts (already trimmed)
     * @return list<string>
     * @see Type::extractTemplateParameterTypeNameList() (Similar method)
     */
    private static function mergeTypeParts(array $parts): array
    {
        $prev_parts = [];
        $delta = 0;
        $results = [];
        foreach ($parts as $part) {
            $type = $part->type;
            if (\count($prev_parts) > 0) {
                $prev_parts[] = $part;
                $delta += \substr_count($type, '<') + \substr_count($type, '(') + \substr_count($type, '{') - \substr_count($type, '>') - \substr_count($type, ')') - \substr_count($type, '}');
                if ($delta <= 0) {
                    if ($delta === 0) {
                        $results[] = TypePart::combine($prev_parts);
                    }  // ignore unparsable data such as "<T,T2>>"
                    $prev_parts = [];
                    $delta = 0;
                    continue;
                }
                continue;
            }
            $bracket_count = \substr_count($type, '<') + \substr_count($type, '(') + \substr_count($type, '{');
            if ($bracket_count === 0) {
                $results[] = $part;
                continue;
            }
            $delta = $bracket_count - \substr_count($type, '>') - \substr_count($type, ')') - \substr_count($type, '}');
            if ($delta === 0) {
                $results[] = $part;
            } elseif ($delta > 0) {
                $prev_parts[] = $part;
            }  // otherwise ignore unparsable data such as ">" (should be impossible)
        }
        return self::convertTypePartsToParseableStrings($results);
    }

    /**
     * @param list<TypePart> $parts
     * @return list<string>
     */
    private static function convertTypePartsToParseableStrings(array $parts): array
    {
        $union = [];
        $intersection = [];
        foreach ($parts as $part) {
            if ($part->separator === '&') {
                $intersection[] = $part->type;
                continue;
            }
            if ($intersection) {
                $union[] = count($intersection) >= 2 ? 'phan-intersection-type<' . implode(',', $intersection) . '>' : $intersection[0];
            }
            $intersection = [$part->type];
        }
        if ($intersection) {
            $union[] = count($intersection) >= 2 ? 'phan-intersection-type<' . implode(',', $intersection) . '>' : $intersection[0];
        }
        return $union;
    }

    /**
     * @param ?\ReflectionType $reflection_type
     *
     * @return UnionType
     * A UnionType with 0 or more nullable/non-nullable Types
     * (limited to at most 1 in php 7, unlimited in php 8)
     */
    public static function fromReflectionType(?\ReflectionType $reflection_type): UnionType
    {
        if ($reflection_type !== null) {
            return self::fromStringInContext(
                Type::stringFromReflectionType($reflection_type),
                new Context(),
                Type::FROM_TYPE
            );
        }
        return self::$empty_instance;
    }

    /**
     * @return array<string,string>
     * Get a map from property name to its type for the given
     * class name.
     */
    public static function internalPropertyMapForClassName(
        string $class_name
    ): array {
        $map = self::internalPropertyMap();

        $canonical_class_name = \strtolower($class_name);

        return $map[$canonical_class_name] ?? [];
    }

    /**
     * @return array<string,array<string,string>>
     * A map from builtin class properties to type information
     *
     * @see \Phan\Language\Internal\PropertyMap
     */
    public static function internalPropertyMap(): array
    {
        static $map = [];

        if (!$map) {
            $map_raw = require(__DIR__ . '/Internal/PropertyMap.php');
            foreach ($map_raw as $key => $value) {
                $map[\strtolower($key)] = $value;
            }

            // Merge in an empty type for dynamic properties on any
            // classes listed as supporting them.
            foreach (require(__DIR__ . '/Internal/DynamicPropertyMap.php') as $class_name) {
                $map[\strtolower($class_name)]['*'] = '';
            }
        }

        return $map;
    }

    /**
     * A list of types for parameters associated with the
     * given builtin function with the given name
     *
     * @param FullyQualifiedMethodName|FullyQualifiedFunctionName $function_fqsen
     *
     * @return list<array{return_type:?UnionType,parameter_name_type_map:array<string,UnionType>}>
     *
     * @see internal_varargs_check
     * Formerly `function internal_varargs_check`
     */
    public static function internalFunctionSignatureMapForFQSEN(
        $function_fqsen
    ): array {
        $map = self::internalFunctionSignatureMap(Config::get_closest_target_php_version_id());

        if ($function_fqsen instanceof FullyQualifiedMethodName) {
            $class_fqsen =
                $function_fqsen->getFullyQualifiedClassName();
            $class_name = $class_fqsen->getNamespacedName();
            $function_name =
                $class_name . '::' . $function_fqsen->getName();
        } else {
            $function_name = $function_fqsen->getNamespacedName();
        }

        $function_name = \strtolower($function_name);

        $function_name_original = $function_name;
        $alternate_id = 0;

        /**
         * @param string|null $type_name
         * @return UnionType|null
         */
        $get_for_global_context = static function (?string $type_name): ?UnionType {
            if (!\is_string($type_name) || $type_name === '') {
                return null;
            }

            static $internal_fn_cache = [];


            $result = $internal_fn_cache[$type_name] ?? null;
            if ($result === null) {
                $context = new Context();
                $result = UnionType::fromStringInContext($type_name, $context, Type::FROM_PHPDOC);
                $internal_fn_cache[$type_name] = $result;
            }
            return $result;
        };

        $configurations = [];
        while (isset($map[$function_name])) {
            // Get some static data about the function
            $type_name_struct = $map[$function_name];

            // Figure out the return type
            $return_type_name = $type_name_struct[0];
            $return_type = $get_for_global_context($return_type_name);

            $parameter_name_type_map = [];

            foreach ($type_name_struct as $name => $type_name) {
                if (\is_int($name)) {
                    // Integer key names are reserved for metadata in the future.
                    continue;
                }
                $parameter_name_type_map[$name] = $get_for_global_context($type_name) ?? self::$empty_instance;
            }

            $configurations[] = [
                'return_type' => $return_type,
                'parameter_name_type_map' => $parameter_name_type_map,
            ];

            $function_name =
                $function_name_original . '\'' . (++$alternate_id);
        }

        return $configurations;
    }

    /**
     * @return list<Type>
     * The list of simple types associated with this
     * union type. Keys are consecutive.
     */
    public function getTypeSet(): array
    {
        return $this->type_set;
    }

    /**
     * @return list<Type>
     * The list of simple types associated with this
     * union type. Keys are consecutive. Intersection types are flattened.
     */
    public function getUniqueFlattenedTypeSet(): array
    {
        if (!$this->hasTypeMatchingCallback(static function (Type $type): bool {
            return $type instanceof IntersectionType;
        })) {
            return $this->type_set;
        }
        $new_types = [];
        foreach ($this->type_set as $inner) {
            foreach ($inner instanceof IntersectionType ? $inner->getTypeParts() : [$inner] as $part) {
                if (!in_array($part, $new_types, true)) {
                    $new_types[] = $part;
                }
            }
        }
        return $new_types;
    }

    /**
     * @return bool true if this has a non-empty real type set.
     */
    public function hasRealTypeSet(): bool
    {
        return (bool)$this->real_type_set;
    }

    /**
     * @return list<Type>
     * The list of real simple types associated with this
     * union type. Keys are consecutive.
     *
     * If this is empty, the real union type is unknown
     */
    public function getRealTypeSet(): array
    {
        return $this->real_type_set;
    }

    /**
     * Add a type name to the list of types
     * @phan-pure
     */
    public function withType(Type $type): UnionType
    {
        // TODO: Figure out a better way to specify if involved types are real
        $type_set = $this->type_set;
        if (\count($type_set) === 0) {
            return $type->withErasedUnionTypes()->asPHPDocUnionType();
        }
        if (\in_array($type, $type_set, true)) {
            return $this->eraseRealTypeSetRecursively();
        }
        // 2 or more types in type_set
        $type_set[] = $type;
        return new UnionType($type_set, true, []);
    }

    /**
     * Returns a new union type
     * which removes this type from the list of types,
     * keeping the keys in a consecutive order.
     *
     * Each type in $this->type_set occurs exactly once.
     * @phan-pure
     */
    public function withoutType(Type $type): UnionType
    {
        // Copy the array $this->type_set
        $type_set = $this->type_set;
        foreach ($type_set as $key => $other_type) {
            if ($type === $other_type) {
                // Remove the only instance of $type from the copy.
                // TODO: Make this work for removing from the real type set
                unset($type_set[$key]);
                return UnionType::ofUnique(\array_values($type_set), []);
            }
        }
        // We did not find $type in type_set. The resulting union type is unchanged.
        return $this->eraseRealTypeSetRecursively();
    }

    /**
     * @return bool
     * True if this union type contains the given named
     * type.
     */
    public function hasType(Type $type): bool
    {
        return \in_array($type, $this->type_set, true);
    }

    /**
     * Returns a union type with an empty real type set
     * @phan-pure
     * @suppress PhanUnreferencedPublicMethod
     */
    public function eraseRealTypeSet(): UnionType
    {
        if ($this->real_type_set) {
            if (\count($this->type_set) === 1) {
                // @phan-suppress-next-line PhanPossiblyNonClassMethodCall
                return \reset($this->type_set)->asPHPDocUnionType();
            }
            return new UnionType($this->type_set, true, []);
        }
        return $this;
    }

    /**
     * @var ?UnionType|?true this union type, with the real type set erased.
     *
     * True if this union type already has all real types erased, to make it possible to garbage collect this with cycle detection disabled.
     */
    private $with_erased_real_type_set = null;

    /**
     * Returns a union type with an empty real type set (including in elements of generic arrays, etc.)
     * @suppress PhanAccessReadOnlyProperty
     */
    public function eraseRealTypeSetRecursively(): UnionType
    {
        $with_erased_real_type_set = $this->with_erased_real_type_set;
        if (\is_null($with_erased_real_type_set)) {
            $this->with_erased_real_type_set = $with_erased_real_type_set = $this->computeEraseRealTypeSetRecursively();
        }
        if (\is_object($with_erased_real_type_set)) {
            return $with_erased_real_type_set;
        }
        return $this;
    }

    /** @return UnionType|true */
    private function computeEraseRealTypeSetRecursively()
    {
        $new_type_set = [];
        foreach ($this->type_set as $type) {
            $new_type_set[] = $type->withErasedUnionTypes();
        }
        if (!$this->real_type_set && $new_type_set === $this->type_set) {
            return $this;
        }
        return UnionType::of($new_type_set);
    }

    /**
     * Returns a union type which adds the given types to this type
     * @phan-pure
     */
    public function withUnionType(UnionType $union_type): UnionType
    {
        // Precondition: Both UnionTypes have lists of unique types.
        $type_set = $this->type_set;
        if (\count($type_set) === 0) {
            return $union_type->eraseRealTypeSetRecursively();
        }
        $other_type_set = $union_type->type_set;

        if (\count($other_type_set) === 0) {
            return $this->eraseRealTypeSetRecursively();
        }
        return UnionType::ofUnique(
            self::mergeUniqueTypes($type_set, $other_type_set),
            ($this->real_type_set && $union_type->real_type_set) ? self::mergeUniqueTypes($this->real_type_set, $union_type->real_type_set) : []
        );
    }

    /**
     * @return bool
     * True if this type has a type referencing the
     * class context in which it exists such as 'self'
     * or '$this'
     */
    public function hasSelfType(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isSelfType()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool
     * True if this union type has any types that are bool/false/true types
     */
    public function hasTypeInBoolFamily(): bool
    {
        return $this->hasTypeMatchingCallback(static function (Type $type): bool {
            return $type->isInBoolFamily();
        });
    }

    /**
     * Returns the types for which is_bool($x) would be true.
     *
     * @return UnionType
     * A UnionType with known bool types kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     * @suppress PhanUnreferencedPublicMethod this is referenced dynamically in RedundantConditionCallPlugin
     */
    public function getTypesInBoolFamily(): UnionType
    {
        return $this->makeFromFilter(static function (Type $type): bool {
            return $type->isInBoolFamily();
        });
    }

    /**
     * Returns this union type after asserting is_bool($x)
     * @suppress PhanUnreferencedPublicMethod this is referenced dynamically in ConditionVisitor
     */
    public function boolTypes(): UnionType
    {
        return UnionType::of(
            self::filterTypesInBoolFamily($this->type_set),
            self::filterTypesInBoolFamily($this->real_type_set)
        )->asNormalizedTypes();
    }

    /**
     * @param Type[] $type_list
     * @return list<Type>
     */
    private static function filterTypesInBoolFamily(array $type_list): array
    {
        $result = [];
        foreach ($type_list as $type) {
            if ($type->isInBoolFamily()) {
                $result[] = $type->withIsNullable(false);
            } elseif ($type instanceof MixedType) {
                if ($type instanceof NonEmptyMixedType) {
                    $result[] = TrueType::instance(false);
                } else {
                    return [BoolType::instance(false)];
                }
            }
        }
        return $result ?: [BoolType::instance(false)];
    }

    /**
     * @param CodeBase $code_base
     * The code base to look up classes against
     *
     * TODO: Defer resolving the template parameters until parse ends. Low priority.
     *
     * @return array<string,UnionType>
     * A map from template type identifiers to the UnionType
     * to replace it with
     */
    public function getTemplateParameterTypeMap(
        CodeBase $code_base
    ): array {
        if ($this->isEmpty()) {
            return [];
        }

        return \array_reduce(
            $this->type_set,
            /**
             * @param array<string,UnionType> $map
             * @return array<string,UnionType>
             */
            static function (array $map, Type $type) use ($code_base): array {
                return \array_merge(
                    $type->getTemplateParameterTypeMap($code_base),
                    $map
                );
            },
            []
        );
    }


    /**
     * @param array<string,UnionType> $template_parameter_type_map
     * A map from template type identifiers to concrete types
     *
     * @return UnionType
     * This UnionType with any template types contained herein
     * mapped to concrete types defined in the given map.
     */
    public function withTemplateParameterTypeMap(
        array $template_parameter_type_map
    ): UnionType {
        $has_template = false;
        $concrete_type_list = [];
        foreach ($this->type_set as $type) {
            $new_union_type = $type->withTemplateParameterTypeMap($template_parameter_type_map);
            if ($new_union_type->isType($type)) {
                $concrete_type_list[] = $type;
            } else {
                $has_template = true;
                foreach ($new_union_type->getTypeSet() as $new_type) {
                    $concrete_type_list[] = $new_type;
                }
            }
        }

        return $has_template ? UnionType::of($concrete_type_list, $this->real_type_set) : $this;
    }

    /**
     * @return bool
     * True if this union type has any types that are template types
     * (e.g. true for the template type T, false for MyClass<T>)
     */
    public function hasTemplateType(): bool
    {
        return $this->hasTypeMatchingCallback(static function (Type $type): bool {
            return ($type instanceof TemplateType);
        });
    }

    /**
     * @return bool
     * True if this union type has any types that are template types
     * (e.g. true for the template type T, true for MyClass<T>, true for T[], false for \MyClass<\stdClass>)
     */
    public function hasTemplateTypeRecursive(): bool
    {
        return $this->hasTypeMatchingCallback(static function (Type $type): bool {
            return $type->hasTemplateTypeRecursive();
        });
    }

    /**
     * @return UnionType
     * Removes template types from this union type, e.g. converts T|\stdClass to \stdClass.
     * @suppress PhanUnreferencedPublicMethod
     */
    public function withoutTemplateTypeRecursive(): UnionType
    {
        return $this->makeFromFilter(static function (Type $type): bool {
            return !$type->hasTemplateTypeRecursive();
        });
    }

    /**
     * Convert `\MyClass<T>` and `\MyClass<\OtherClass>` to just `\MyClass`.
     */
    public function eraseTemplatesRecursive(): UnionType
    {
        return $this->asMappedUnionType(static function (Type $type): Type {
            return $type->eraseTemplatesRecursive();
        });
    }

    /**
     * @return bool
     * True if this union type has any types that have generic
     * types
     */
    public function hasTemplateParameterTypes(): bool
    {
        return $this->hasTypeMatchingCallback(static function (Type $type): bool {
            return $type->hasTemplateParameterTypes();
        });
    }

    /**
     * @return bool
     * True if this type has a type referencing the
     * class context 'static' at the top level.
     */
    public function hasStaticType(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type instanceof StaticType) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return UnionType
     * A new UnionType with any references to 'static' resolved
     * in the given context.
     */
    public function withStaticResolvedInContext(
        Context $context
    ): UnionType {

        // If the context isn't in a class scope,
        // there's nothing we can do
        if (!$context->isInClassScope()) {
            return $this;
        }
        return $this->asMappedUnionType(static function (Type $type) use ($context): Type {
            return $type->withStaticResolvedInContext($context);
        });
    }

    /**
     * @return UnionType
     * A new UnionType with any references to 'static' resolved
     * in the given function or method's context.
     */
    public function withStaticResolvedInFunctionLike(
        FunctionInterface $function
    ): UnionType {
        $context = $function->getContext();
        if ($function instanceof Method) {
            // Gets the context of the method *after* inheritance
            $context = $context->withScope(new class ($function->getClassFQSEN()) extends Scope {
                /** @var FullyQualifiedClassName the name being resolved */
                private $class_fqsen;
                public function __construct(FullyQualifiedClassName $fqsen)
                {
                    $this->class_fqsen = $fqsen;
                }

                /**
                 * @override
                 */
                public function isInClassScope(): bool
                {
                    return true;
                }

                /**
                 * @override
                 */
                public function getClassFQSEN(): FullyQualifiedClassName
                {
                    return $this->class_fqsen;
                }
            });
        }
        return $this->withStaticResolvedInContext($context);
    }

    /**
     * @return UnionType
     * A new UnionType with any references to 'self' (but not 'static') resolved
     * in the given context.
     */
    public function withSelfResolvedInContext(
        Context $context
    ): UnionType {
        // If the context isn't in a class scope, or if it doesn't have 'self',
        // there's nothing we can do
        if (!$context->isInClassScope() || !$this->hasSelfType()) {
            return $this;
        }

        return $this->asMappedUnionType(static function (Type $type) use ($context): Type {
            if ($type instanceof SelfType) {
                return $type->withSelfResolvedInContext($context);
            }
            return $type;
        });
    }

    /**
     * @return UnionType
     * A new UnionType *plus* any references to 'self' (but not 'static') resolved
     * in the given context.
     */
    public function withAddedClassForResolvedSelf(
        Context $context
    ): UnionType {
        // If the context isn't in a class scope, or if it doesn't have 'self',
        // there's nothing we can do
        if (!$context->isInClassScope() || !$this->hasSelfType()) {
            return $this;
        }

        $is_nullable = $this->containsNullable();
        $resolved_type = $context->getClassFQSEN()->asType()->withIsNullable($is_nullable);
        return UnionType::of(
            \array_merge($this->type_set, [$resolved_type]),
            $this->real_type_set
        );
    }

    /**
     * @return bool
     * True if and only if this UnionType contains
     * the given type and no others.
     */
    public function isType(Type $type): bool
    {
        $type_set = $this->type_set;
        if (\count($type_set) !== 1) {
            return false;
        }

        return \reset($type_set) === $type;
    }

    /**
     * @return bool
     * True if this UnionType is exclusively native
     * types
     */
    public function isNativeType(): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        return $this->allTypesMatchCallback(static function (Type $type): bool {
            return $type->isNativeType();
        });
    }

    /**
     * @return bool
     * True iff this union contains the exact set of types
     * represented in the given union type.
     *
     * This does not check real types.
     */
    public function isEqualTo(UnionType $union_type): bool
    {
        if ($this === $union_type) {
            // true about half the time.
            return true;
        }
        $type_set = $this->type_set;
        $other_type_set = $union_type->type_set;
        if (\count($type_set) !== \count($other_type_set)) {
            return false;
        }
        foreach ($type_set as $type) {
            if (!\in_array($type, $other_type_set, true)) {
                return false;
            }
        }
        return !$union_type->isPossiblyUndefined();
    }

    /**
     * @return bool
     * True iff this union contains the exact set of types
     * represented in the given union type.
     *
     * This checks real types.
     */
    public function isIdenticalTo(UnionType $union_type): bool
    {
        if ($this === $union_type) {
            // true about half the time.
            return true;
        }
        $type_set = $this->type_set;
        $other_type_set = $union_type->type_set;
        if (\count($type_set) !== \count($other_type_set)) {
            return false;
        }
        foreach ($type_set as $type) {
            if (!\in_array($type, $other_type_set, true)) {
                return false;
            }
        }
        $real_type_set = $this->real_type_set;
        $other_real_type_set = $union_type->real_type_set;
        if (\count($real_type_set) !== \count($other_real_type_set)) {
            return false;
        }
        foreach ($real_type_set as $type) {
            if (!\in_array($type, $other_real_type_set, true)) {
                return false;
            }
        }
        return !$union_type->isPossiblyUndefined();
    }

    /**
     * @return bool
     * True iff this union contains a type that's also in
     * the other union type.
     *
     * This does not check real types.
     */
    public function hasCommonType(UnionType $union_type): bool
    {
        $other_type_set = $union_type->type_set;
        if (\count($other_type_set) > 4 && \count($this->type_set) > 4) {
            return $this->hasCommonTypeSetCheck($other_type_set);
        }
        foreach ($this->type_set as $type) {
            if (\in_array($type, $other_type_set, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if $this->type_set and $other_type_list have any types in common.
     * For two union types with 10 types, this takes at most 20 operations instead of 100 operations.
     *
     * @param list<Type> $other_type_list
     */
    private function hasCommonTypeSetCheck(array $other_type_list): bool
    {
        $type_set = [];
        // Avoid worst-case quadratic runtime
        foreach ($this->type_set as $type) {
            $type_set[\spl_object_id($type)] = true;
        }
        foreach ($other_type_list as $type) {
            if (\array_key_exists(\spl_object_id($type), $type_set)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return UnionType this union type without the subclasses/sub-types of $object_type
     */
    public function withoutSubclassesOf(CodeBase $code_base, Type $object_type): UnionType
    {
        return UnionType::of(
            self::typesWithoutSubclassesOf($code_base, $this->type_set, $object_type),
            self::typesWithoutSubclassesOf($code_base, $this->real_type_set, $object_type)
        );
    }

    /**
     * @param list<Type> $type_set
     * @return list<Type> the subset of types in $type_set excluding the subclasses/sub-types of $object_type
     */
    public static function typesWithoutSubclassesOf(CodeBase $code_base, array $type_set, Type $object_type): array
    {
        $is_nullable = false;
        $new_type_set = [];

        foreach ($type_set as $type) {
            if ($type->isNullableLabeled()) {
                $is_nullable = true;
            }
            if ($type->withIsNullable(false)->isSubclassOf($object_type, $code_base)) {
                continue;
            }
            $new_type_set[] = $type;
        }
        if (!$is_nullable) {
            return $new_type_set;
        }
        if (!$new_type_set) {
            // There was a null somewhere in the old union type.
            return [NullType::instance(false)];
        }
        foreach ($new_type_set as $i => $type) {
            $new_type_set[$i] = $type->withIsNullable(true);
        }
        return $new_type_set;
    }

    /**
     * @return bool - True if not empty and at least one type is NullType or nullable.
     */
    public function containsNullable(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isNullable()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool - True if not empty and at least one type is NullType or VoidType or marked with `?`
     * (same as containsNullable but excludes mixed)
     */
    public function containsNullableLabeled(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isNullableLabeled()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool - True if not empty and at least one type is NullType or nullable.
     *
     * To reduce false positives for unknown array element types (etc.),
     * this distinguishes between the phpdoc types ?mixed and mixed,
     * even though both can contain false.
     */
    public function containsNonMixedNullable(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isNullableLabeled()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool - True if empty or at least one type is NullType or nullable.
     * e.g. true for `?int`, `int|null`, or ``
     */
    public function containsNullableOrIsEmpty(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isNullable()) {
                return true;
            }
        }
        return \count($this->type_set) === 0;
    }

    /**
     * @return bool - True if not empty and at least one type is NullType or mixed.
     * TODO deprecate and remove
     */
    public function containsNullableOrMixed(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isNullable()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool - True if not empty, not possibly undefined, and at least one type is NullType or nullable.
     */
    public function containsNullableOrUndefined(): bool
    {
        return $this->containsNullable();
    }

    /**
     * Returns true if is_null(expr) is unconditionally true for this type
     */
    public function isNull(): bool
    {
        foreach ($this->type_set as $type) {
            if (!($type instanceof NullType) && !($type instanceof VoidType)) {
                return false;
            }
        }
        return \count($this->type_set) !== 0;
    }

    /**
     * Returns true if !isset(expr) is unconditionally true for the real types
     */
    public function isRealTypeNullOrUndefined(): bool
    {
        foreach ($this->real_type_set as $type) {
            if (!($type instanceof NullType) && !($type instanceof VoidType)) {
                return false;
            }
        }
        return \count($this->type_set) !== 0;
    }

    /**
     * @return UnionType a clone of this that does not include null,
     *                   and has the non-null equivalents of any nullable types in this UnionType
     */
    public function nonNullableClone(): UnionType
    {
        return self::of(
            self::toNonNullableTypeList($this->type_set),
            self::toNonNullableTypeList($this->real_type_set)
        );
    }

    /**
     * @param Type[] $type_list
     * @return list<Type> a list of non-nullable types that may contain duplicates.
     */
    private static function toNonNullableTypeList(array $type_list): array
    {
        $result = [];
        foreach ($type_list as $type) {
            if (!$type->isNullable()) {
                $result[] = $type;
                continue;
            }
            if ($type instanceof NullType || $type instanceof VoidType) {
                continue;
            }

            $result[] = $type->withIsNullable(false);
        }
        return $result ?: UnionType::typeSetFromString('non-null-mixed');
    }


    /**
     * @return UnionType a clone of this that has the nullable equivalents of any types in this UnionType
     * (e.g. returns '?T|?false' for 'T|false')
     */
    public function nullableClone(): UnionType
    {
        return self::of(
            self::toNullableTypeList($this->type_set),
            self::toNullableTypeList($this->real_type_set)
        );
    }

    /**
     * This can be used to handle the fact that the real types might be nullable to avoid false positives in redundant condition detection (e.g. accessing an array offset)
     */
    public function withNullableRealTypes(): UnionType
    {
        if (!$this->real_type_set) {
            return $this;
        }
        $real_type_set = self::toNullableTypeList($this->real_type_set);
        if ($real_type_set === $this->real_type_set) {
            return $this;
        }
        return self::of($this->type_set, $real_type_set);
    }

    /**
     * @param Type[] $type_list
     * @return list<Type> a list of nullable types that may contain duplicates.
     */
    private static function toNullableTypeList(array $type_list): array
    {
        $result = [];
        foreach ($type_list as $type) {
            if ($type->isNullableLabeled()) {
                $result[] = $type;
            } else {
                $result[] = $type->withIsNullable(true);
            }
        }
        return $result;
    }

    /**
     * Analogous to Type->withIsNullable()
     * @suppress PhanUnreferencedPublicMethod
     * @see self::nullableClone()
     * @see self::nonNullableClone()
     */
    public function withIsNullable(bool $is_nullable): UnionType
    {
        return $is_nullable ? $this->nullableClone() : $this->nonNullableClone();
    }

    /**
     * @return bool - True if type set is not empty and at least one type is NullType or nullable or FalseType or BoolType.
     * (I.e. the type is always falsey, or both sometimes falsey with a non-falsey type it can be narrowed down to)
     */
    public function containsFalsey(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isPossiblyFalsey()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return UnionType a clone of this with any falsey types (null, false, falsey int/string literals, etc.) removed.
     */
    public function nonFalseyClone(): UnionType
    {
        return UnionType::of(
            self::toNonFalseyTypeSet($this->type_set),
            self::toNonFalseyTypeSet($this->real_type_set)
        );
    }

    /**
     * @param list<Type> $type_set
     * @return list<Type> which may contain duplicates
     */
    private static function toNonFalseyTypeSet(array $type_set): array
    {
        $result = [];
        foreach ($type_set as $type) {
            if (!$type->isPossiblyFalsey()) {
                $result[] = $type;
                continue;
            }
            if ($type->isAlwaysFalsey()) {
                // don't add null/false to the resulting type
                continue;
            }

            // add non-nullable equivalents, and replace BoolType with non-nullable TrueType
            $result[] = $type->asNonFalseyType();
        }
        // TODO: Preserve real types
        if ($result) {
            return $result;
        }
        static $non_empty_mixed = null;
        return $non_empty_mixed ?? ($non_empty_mixed = UnionType::typeSetFromString('non-empty-mixed'));
    }

    /**
     * @return bool - True if type set is not empty and at least one type is possibly truthy.
     */
    public function containsTruthy(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isPossiblyTruthy()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true if this contains at least one IntType or LiteralIntType
     */
    public function hasIntType(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type instanceof IntType) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true if this contains at least one non-null IntType or LiteralIntType
     */
    public function hasNonNullIntType(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type instanceof IntType && !$type->isNullable()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true if this type's real type set is exclusively non-null float types and is non-empty
     */
    public function isExclusivelyRealFloatTypes(): bool
    {
        foreach ($this->real_type_set as $type) {
            if (!($type instanceof FloatType) || $type->isNullable()) {
                return false;
            }
        }
        return \count($this->real_type_set) > 0;
    }

    /**
     * Returns true if this is exclusively non-null IntType or LiteralIntType
     */
    public function isNonNullIntType(): bool
    {
        if (\count($this->type_set) === 0) {
            return false;
        }
        foreach ($this->type_set as $type) {
            if (!($type instanceof IntType) || $type->isNullable()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns true if this is exclusively IntType or LiteralIntType or NullType
     */
    public function isIntTypeOrNull(): bool
    {
        if (\count($this->type_set) === 0) {
            return false;
        }
        foreach ($this->type_set as $type) {
            if (!($type instanceof IntType) && !($type instanceof NullType)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns true if this is exclusively non-null IntType or LiteralIntType or FloatType or LiteralFloatType
     */
    public function isNonNullIntOrFloatType(): bool
    {
        if (\count($this->type_set) === 0) {
            return false;
        }
        foreach ($this->type_set as $type) {
            if (!($type instanceof IntType || $type instanceof FloatType) || $type->isNullable()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns true if this is exclusively non-null IntType or FloatType or subclasses
     */
    public function isNonNullNumberType(): bool
    {
        // TODO: This duplicates isNonNullIntOrFloatType
        if (\count($this->type_set) === 0) {
            return false;
        }
        foreach ($this->type_set as $type) {
            if (!($type instanceof IntType || $type instanceof FloatType) || $type->isNullable()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns true if this contains at least one StringType or LiteralStringType
     */
    public function hasStringType(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type instanceof StringType) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true if this contains at least one non-null StringType or LiteralStringType
     */
    public function hasNonNullStringType(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type instanceof StringType && !$type->isNullable()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true if this is exclusively non-null StringType or LiteralStringType
     */
    public function isNonNullStringType(): bool
    {
        if (\count($this->type_set) === 0) {
            return false;
        }
        foreach ($this->type_set as $type) {
            if (!($type instanceof StringType) || $type->isNullable()) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return bool if this contains at least one literal int/float/string type (e.g. `'myString'|false`)
     */
    public function hasLiterals(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type instanceof LiteralTypeInterface) {
                $value = $type->getValue();
                if (!\is_null($value) && !\is_bool($value)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return UnionType result of converting literal int/string types to the non-literal equivalents
     * (e.g. converts `'myString'|false` to `string|false`)
     */
    public function asNonLiteralType(): UnionType
    {
        if (!$this->hasLiterals()) {
            return $this;
        }
        // Could use asMappedUnionType but this may be frequently called
        $new_types = [];
        foreach ($this->type_set as $type) {
            $new_types[] = $type->asNonLiteralType();
        }
        $new_real_types = [];
        foreach ($this->real_type_set as $type) {
            $new_real_types[] = $type->asNonLiteralType();
        }
        return UnionType::of($new_types, $new_real_types);
    }

    /**
     * @return UnionType result of removing truthy types from this value
     * (e.g. converts `0|1|bool|\stdClass` to `0|false`)
     * (e.g. converts `?\stdClass` to `null`)
     */
    public function nonTruthyClone(): UnionType
    {
        return UnionType::of(
            self::toNonTruthyTypeSet($this->type_set),
            self::toNonTruthyTypeSet($this->real_type_set)
        );
    }

    /**
     * @param list<Type> $type_set
     * @return list<Type>
     */
    private static function toNonTruthyTypeSet(array $type_set): array
    {
        $result = [];
        foreach ($type_set as $type) {
            if (!$type->isPossiblyTruthy()) {
                $result[] = $type;
                continue;
            }
            if ($type->isAlwaysTruthy()) {
                // don't add null/false to the resulting type
                continue;
            }

            // add non-nullable equivalents, and replace BoolType with non-nullable TrueType
            $result[] = $type->asNonTruthyType();
        }
        return $result;
    }

    /**
     * @return bool - True if type set is not empty and at least one type is BoolType or FalseType
     */
    public function containsFalse(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isPossiblyFalse()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool - True if type set is not empty and at least one type is BoolType or TrueType
     */
    public function containsTrue(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isPossiblyTrue()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return UnionType result of removing false from this value
     * (e.g. converts `0|1|bool|\stdClass` to `0|1|true|\stdClass`)
     */
    public function nonFalseClone(): UnionType
    {
        $builder = new UnionTypeBuilder();
        $did_change = false;
        foreach ($this->type_set as $type) {
            if (!$type->isPossiblyFalse()) {
                $builder->addType($type);
                continue;
            }
            $did_change = true;
            if ($type->isAlwaysFalse()) {
                // don't add null/false to the resulting type
                continue;
            }

            // add non-nullable equivalents, and replace BoolType with non-nullable TrueType
            $builder->addType($type->asNonFalseType());
        }
        return $did_change ? $builder->getPHPDocUnionType() : $this;
    }

    /**
     * @return UnionType result of removing true from this value
     * (e.g. converts `0|1|bool|\stdClass` to `0|1|false|\stdClass`)
     */
    public function nonTrueClone(): UnionType
    {
        $builder = new UnionTypeBuilder();
        $did_change = false;
        foreach ($this->type_set as $type) {
            if (!$type->isPossiblyTrue()) {
                $builder->addType($type);
                continue;
            }
            $did_change = true;
            if ($type->isAlwaysTrue()) {
                // don't add null/false to the resulting type
                continue;
            }

            // add non-nullable equivalents, and replace BoolType with non-nullable TrueType
            $builder->addType($type->asNonTrueType());
        }
        return $did_change ? $builder->getPHPDocUnionType() : $this;
    }

    /**
     * @param UnionType $union_type
     * A union type to compare against
     *
     * @param Context $context
     * The context in which this type exists.
     *
     * @param CodeBase $code_base
     * The code base in which both this and the given union
     * types exist.
     *
     * @return bool
     * True if each type within this union type can cast
     * to the given union type.
     */
    // Currently unused and buggy, commenting this out.
    /**
    public function isExclusivelyNarrowedFormOrEquivalentTo(
        UnionType $union_type,
        Context $context,
        CodeBase $code_base
    ) : bool {

        // Special rule: anything can cast to nothing
        // and nothing can cast to anything
        if ($union_type->isEmpty() || $this->isEmpty()) {
            return true;
        }

        // Check to see if the types are equivalent
        if ($this->isEqualTo($union_type)) {
            return true;
        }
        // TODO: Allow casting MyClass<TemplateType> to MyClass (Without the template?)

        // Resolve 'static' for the given context to
        // determine what's actually being referred
        // to in concrete terms.
        $other_resolved_type =
            $union_type->withStaticResolvedInContext($context);
        $other_resolved_type_set = $other_resolved_type->type_set;

        // Convert this type to a set of resolved types to iterate over.
        $this_resolved_type_set =
            $this->withStaticResolvedInContext($context)->type_set;

        // Convert this type to an array of resolved
        // types.
        $type_set =
            $this->withStaticResolvedInContext($context)
            ->getTypeSet();
        // TODO: Need to resolve expanded union types (parents, interfaces) of classes *before* this is called.

        // Test to see if every single type in this union
        // type can cast to the given union type.
        foreach ($this_resolved_type_set as $type) {
            // First check if this contains the type as an optimization.
            if ($other_resolved_type_set->contains($type)) {
                continue;
            }
            $expanded_types = $type->asExpandedTypes($code_base);
            if ($other_resolved_type->canCastToUnionType(
                $expanded_types
            )) {
                continue;
            }
        }
        return true;
    }
     */

    // TODO: Callers should call withStaticResolvedInContext?
    public function isExclusivelyNarrowedFormOf(CodeBase $code_base, UnionType $other): bool
    {
        if ($other->isEmpty()) {
            return true;
        }
        if ($this->isEmpty() || $this->hasMixedTypeStrict()) {
            return false;
        }
        foreach ($this->type_set as $type) {
            if (!$type->asPHPDocUnionType()->canStrictCastToUnionType($code_base, $other)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param Type[] $type_list
     * A list of types
     *
     * @return bool
     * True if this union type contains any of the given
     * named types
     */
    public function hasAnyType(array $type_list): bool
    {
        $type_set = $this->type_set;
        if (\count($type_set) === 0) {
            return false;
        }
        foreach ($type_list as $type) {
            if (\in_array($type, $type_set, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool
     * True if this type has any subtype of the `iterable` type (e.g. `Traversable`, `Array`).
     */
    public function hasIterable(CodeBase $code_base): bool
    {
        return $this->hasTypeMatchingCallback(static function (Type $type) use ($code_base): bool {
            return $type->isIterable($code_base);
        });
    }

    /**
     * Returns this union type after asserting is_iterable($x).
     *
     * @return UnionType
     * A UnionType with known iterable types kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     * @suppress PhanUnreferencedPublicMethod
     */
    public function iterableTypesStrictCast(CodeBase $code_base): UnionType
    {
        return UnionType::of(
            self::castToIterableTypesStrict($code_base, $this->type_set, false),
            self::castToIterableTypesStrict($code_base, $this->real_type_set, false)
        );
    }

    /**
     * Similar to iterableTypesStrictCast, but also
     * casts callable-object and object to Traversable.
     * TODO: Could check if classes are final.
     */
    public function iterableTypesStrictCastAssumeTraversable(CodeBase $code_base): UnionType
    {
        return UnionType::of(
            self::castToIterableTypesStrict($code_base, $this->type_set, true),
            self::castToIterableTypesStrict($code_base, $this->real_type_set, true)
        );
    }

    /**
     * Casts the type list to non-null iterables and sub-types of iterable.
     * Preserve classes implementing Traversable.
     * @param Type[] $type_list
     * @return list<Type>
     */
    private static function castToIterableTypesStrict(CodeBase $code_base, array $type_list, bool $object_to_traversable): array
    {
        $result = [];
        foreach ($type_list as $type) {
            $new_type = $type->asIterable($code_base);
            if ($new_type) {
                $result[] = $new_type;
            } elseif ($object_to_traversable && $type->isPossiblyObject()) {
                $result[] = Type::traversableInstance();
            }
        }
        return $result ?: [IterableType::instance(false)];
    }

    /**
     * @return int
     * The number of types in this union type
     */
    public function typeCount(): int
    {
        return \count($this->type_set);
    }

    /**
     * @return bool
     * True if this Union has no types
     */
    public function isEmpty(): bool
    {
        return \count($this->type_set) === 0;
    }

    /**
     * @return bool
     * True if this Union has no types or is the mixed type
     */
    public function isEmptyOrMixed(): bool
    {
        foreach ($this->type_set as $type) {
            if (!$type instanceof MixedType) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param UnionType $target
     * The type we'd like to see if this type can cast
     * to
     *
     * @param CodeBase $code_base
     * The code base used to expand types
     *
     * @return bool
     * Test to see if this type can be cast to the
     * given type after expanding both union types
     * to include all ancestor types
     *
     * TODO: ensure that this is only called after the parse phase is over.
     */
    public function canCastToExpandedUnionType(
        UnionType $target,
        CodeBase $code_base
    ): bool {

        $target_expanded = $target->asExpandedTypes($code_base);

        return $this->canCastToUnionType($target_expanded, $code_base);
    }

    /**
     * Check if a class with templates can be cast to another class with templates
     * At least one of the source or target is expected to have templates when this is called.
     *
     * This allows casting Some<\MyClass> to cast to Option<\MyClass>, but not Option<\UnrelatedClass>
     */
    public function canCastToUnionType(
        UnionType $target,
        CodeBase $code_base
    ): bool {
        // Fast-track most common cases first
        $type_set = $this->type_set;
        // If either type is unknown, we can't call it
        // a success
        if (\count($type_set) === 0) {
            return true;
        }
        $target_type_set = $target->type_set;
        if (\count($target_type_set) === 0) {
            return true;
        }
        $target = $target->asNormalizedTypes();

        // T overlaps with T, a future call to Type->canCastToType will pass.
        if ($this->hasCommonType($target)) {
            return true;
        }
        static $float_type;
        static $int_type;
        static $mixed_type;
        static $null_type;
        if ($null_type === null) {
            $int_type   = IntType::instance(false);
            $float_type = FloatType::instance(false);
            $mixed_type = MixedType::instance(false);
            $null_type  = NullType::instance(false);
        }

        if (Config::get_null_casts_as_any_type()) {
            // null <-> null
            if ($this->isType($null_type)
                || $target->isType($null_type)
            ) {
                return true;
            }
        } else {
            // If null_casts_as_any_type isn't set, then try the other two fallbacks.
            if (Config::get_null_casts_as_array() && $this->isType($null_type) && $target->hasArrayLike($code_base)) {
                return true;
            } elseif (Config::get_array_casts_as_null() && $target->isType($null_type) && $this->hasArrayLike($code_base)) {
                return true;
            }
        }

        // mixed <-> mixed
        if (\in_array($mixed_type, $type_set, true)
            || \in_array($mixed_type, $target_type_set, true)
        ) {
            return true;
        }

        // int -> float
        if (\in_array($int_type, $type_set, true)
            && \in_array($float_type, $target_type_set, true)
        ) {
            return true;
        }

        // Check conversion on the cross product of all
        // type combinations and see if any can cast to
        // any.
        foreach ($type_set as $source_type) {
            if ($source_type->canCastToAnyTypeInSet($target_type_set, $code_base)) {
                return true;
            }
        }

        // Allow casting ?T to T|null for any type T. Check if null is part of this type first.
        if (\in_array($null_type, $target_type_set, true)) {
            foreach ($type_set as $source_type) {
                // Only redo this check for the nullable types, we already failed the checks for non-nullable types.
                if ($source_type->isNullable()) {
                    // TODO: Add unit tests of nullable templates
                    return $source_type->withIsNullable(false)->canCastToAnyTypeInSet($target_type_set, $code_base);
                }
            }
        }

        // Only if no source types can be cast to any target
        // types do we say that we cannot perform the cast
        return false;
    }

    /**
     * @param UnionType $target
     * A type to check to see if this can cast to it
     *
     * @return bool
     * True if this type is allowed to cast to the given type
     * (intended to ignore any permissive config settings, such as null_casts_as_any_type)
     * i.e. int->float is allowed  while float->int is not.
     */
    public function canCastToUnionTypeWithoutConfig(UnionType $target, CodeBase $code_base): bool
    {
        // Fast-track most common cases first
        $type_set = $this->type_set;
        // If either type is unknown, we can't call it
        // a success
        if (\count($type_set) === 0) {
            return true;
        }
        $target_type_set = $target->type_set;
        if (\count($target_type_set) === 0) {
            return true;
        }

        // T overlaps with T, a future call to Type->canCastToType will pass.
        $target = $target->asNormalizedTypes();
        if ($this->hasCommonType($target)) {
            return true;
        }

        static $float_type;
        static $int_type;
        static $mixed_type;
        static $null_type;
        if ($null_type === null) {
            $int_type   = IntType::instance(false);
            $float_type = FloatType::instance(false);
            $mixed_type = MixedType::instance(false);
            $null_type  = NullType::instance(false);
        }

        // mixed <-> mixed
        if (\in_array($mixed_type, $type_set, true)
            || \in_array($mixed_type, $target_type_set, true)
        ) {
            return true;
        }

        // int -> float
        if (\in_array($int_type, $type_set, true)
            && \in_array($float_type, $target_type_set, true)
        ) {
            return true;
        }

        // Check conversion on the cross product of all
        // type combinations and see if any can cast to
        // any.
        foreach ($type_set as $source_type) {
            if ($source_type->canCastToAnyTypeInSetWithoutConfig($target_type_set, $code_base)) {
                return true;
            }
        }

        // Allow casting ?T to T|null for any type T. Check if null is part of this type first.
        if (\in_array($null_type, $target_type_set, true)) {
            foreach ($type_set as $source_type) {
                // Only redo this check for the nullable types, we already failed the checks for non-nullable types.
                if ($source_type->isNullable()) {
                    if ($source_type->withIsNullable(false)->canCastToAnyTypeInSetWithoutConfig($target_type_set, $code_base)) {
                        return true;
                    }
                }
            }
        }

        // Only if no source types can be cast to any target
        // types do we say that we cannot perform the cast
        return false;
    }

    /**
     * Precondition: $this->canCastToUnionType() is false.
     *
     * This tells us if it would have succeeded if the source type was not nullable.
     *
     * @internal
     */
    public function canCastToUnionTypeIfNonNull(UnionType $target, CodeBase $code_base): bool
    {
        $non_null = $this->nonNullableClone();
        if ($non_null === $this) {
            // This wasn't nullable in the first place
            return false;
        }
        if ($non_null->isEmpty()) {
            // This was exclusively null - It should be a full TypeMismatch
            return false;
        }
        return $non_null->canCastToUnionType($target, $code_base);
    }

    /**
     * Checks if any type in this union type can strictly cast to the other type
     *
     * E.g. allows ?T|Other -> T, null|Other -> ?T, ?T -> ?Other
     * Does not allow ?T -> Other, etc.
     *
     * @param bool $allow_casting
     * If true, allow non-identical types to cast to other types if that would be permitted by casting rules for param/return types
     * (e.g. int -> float)
     */
    public function canAnyTypeStrictCastToUnionType(CodeBase $code_base, UnionType $target, bool $allow_casting = true): bool
    {
        foreach ($this->type_set as $type) {
            if ($type instanceof IntType && !$allow_casting) {
                if (!$target->hasTypeMatchingCallback(static function (Type $type): bool {
                    return $type instanceof IntType || $type instanceof MixedType;
                })
                ) {
                    continue;
                }
            }
            if ($type->asPHPDocUnionType()->canStrictCastToUnionType($code_base, $target)) {
                return true;
            }
        }
        return \count($this->type_set) === 0 || ($this->containsNullable() && $target->containsNullable());
    }

    /**
     * @param UnionType $target
     * A type to check to see if this has subtypes of.
     *
     * @return bool
     * True if this type contains subtypes of the other type
     *
     * i.e. array -> iterable is allowed, but iterable -> array is not
     * i.e. MyClass -> mixed is allowed, but mixed -> MyClass is not
     *
     * @suppress PhanStaticClassAccessWithStaticVariable static variables are safely initialized
     */
    public function hasSubtypeOf(UnionType $target, CodeBase $code_base): bool
    {
        // Fast-track most common cases first
        $type_set = $this->type_set;
        // If either type is unknown, we can't call it
        // a success
        if (\count($type_set) === 0) {
            return true;
        }
        $target_type_set = $target->type_set;
        if (\count($target_type_set) === 0) {
            return true;
        }

        // T overlaps with T, a future call to Type->canCastToType will pass.
        $target = $target->asNormalizedTypes();
        if ($this->hasCommonType($target)) {
            return true;
        }

        static $float_type;
        static $int_type;
        static $mixed_type;
        static $null_type;
        if ($null_type === null) {
            $int_type   = IntType::instance(false);
            $float_type = FloatType::instance(false);
            $mixed_type = MixedType::instance(false);
            $null_type  = NullType::instance(false);
        }

        // any -> mixed
        if (\in_array($mixed_type, $target_type_set, true)) {
            return true;
        }

        // int -> float
        // TODO: This should go in IntType->isSubtypeOf?
        if (\in_array($int_type, $type_set, true)
            && \in_array($float_type, $target_type_set, true)
        ) {
            return true;
        }

        // Check conversion on the cross product of all
        // type combinations and see if any can cast to
        // any.
        foreach ($type_set as $source_type) {
            if ($source_type->isSubtypeOfAnyTypeInSet($target_type_set, $code_base)) {
                return true;
            }
        }

        // Allow casting ?T to T|null for any type T. Check if null is part of this type first.
        if (\in_array($null_type, $target_type_set, true)) {
            foreach ($type_set as $source_type) {
                // Only redo this check for the nullable types, we already failed the checks for non-nullable types.
                if ($source_type->isNullable()) {
                    if ($source_type->withIsNullable(false)->isSubtypeOfAnyTypeInSet($target_type_set, $code_base)) {
                        return true;
                    }
                }
            }
        }

        // Only if no source types can be cast to any target
        // types do we say that we cannot perform the cast
        return false;
    }

    /**
     * Checks if any type in this union type weakly overlaps with other types
     *
     * E.g. allows `1 <= 2`, `null == false`, ?T -> ?Other, etc.
     * Does not allow ?T -> Other, etc.
     *
     * NOTE: callers should check that
     */
    protected function canAnyTypeWeakOverlapUnionType(UnionType $target, CodeBase $code_base): bool
    {
        foreach ($this->type_set as $type) {
            foreach ($target->type_set as $other_type) {
                if ($type->weaklyOverlaps($other_type, $code_base)) {
                    return true;
                }
            }
        }
        return \count($this->type_set) === 0 || ($this->containsNullable() && $target->containsNullable());
    }

    /**
     * @param UnionType $target
     * A type to check to see if this can cast to it.
     *
     * Every single type in this type must be able to cast to a type in $target (Empty types can cast to empty)
     *
     * @return bool
     * True if every type in this union type is allowed to cast to the given union type.
     * i.e. int->float is allowed while int|object->float is not.
     *
     * @suppress PhanUnreferencedPublicMethod may be used elsewhere in the future
     */
    public function canStrictCastToUnionType(CodeBase $code_base, UnionType $target): bool
    {
        // Fast-track most common cases first
        $type_set = $this->type_set;
        // If either type is unknown, we can't call it
        // a success
        if (\count($type_set) === 0) {
            return true;
        }
        $target_type_set = $target->type_set;
        if (\count($target_type_set) === 0) {
            return true;
        }

        // every single type in T overlaps with T, a future call to Type->canCastToType will pass.
        $matches = true;
        foreach ($type_set as $type) {
            if (!\in_array($type, $target_type_set, true)) {
                $matches = false;
                break;
            }
        }
        if ($matches) {
            return true;
        }
        static $null_type;
        if ($null_type === null) {
            $null_type  = NullType::instance(false);
        }

        // Check conversion on the cross product of all
        // type combinations and see if any can cast to
        // any.
        $matches = true;
        foreach ($type_set as $source_type) {
            if (!$source_type->asPHPDocUnionType()->canCastToUnionTypeWithoutConfig($target, $code_base)) {
                $matches = false;
                break;
            }
        }
        if ($matches) {
            return true;
        }

        // Allow casting ?T to T|null for any type T. Check if null is part of this type first.
        if (\in_array($null_type, $target_type_set, true)) {
            foreach ($type_set as $source_type) {
                // Only redo this check for the nullable types, we already failed the checks for non-nullable types.
                if (!$source_type->withIsNullable(false)->asPHPDocUnionType()->canCastToUnionTypeWithoutConfig($target, $code_base)) {
                    return false;
                }
            }
            return true;
        }

        // Only if no source types can be cast to any target
        // types do we say that we cannot perform the cast
        return false;
    }

    /**
     * @param UnionType $target
     * A type to check to see if this is a strict subtype of this.
     *
     * Every single type in this type must be a strict subtype of a type in $target (Empty types can cast to empty)
     *
     * @return bool
     * True if every single type in this type is a strict subtype of a type in $target (Empty types can cast to empty)
     * i.e. int->float is allowed  while float->int is not.
     *
     * @suppress PhanUnreferencedPublicMethod may be used elsewhere in the future
     */
    public function isStrictSubtypeOf(CodeBase $code_base, UnionType $target): bool
    {
        // Fast-track most common cases first
        $type_set = $this->type_set;
        // If either type is unknown, we can't call it
        // a success
        if (\count($type_set) === 0) {
            return true;
        }
        $target_type_set = $target->asNormalizedTypes()->type_set;
        if (\count($target_type_set) === 0) {
            return true;
        }

        // every single type in this overlaps with the target, so the strict cast will succeed.
        $matches = true;
        foreach ($type_set as $type) {
            if (!\in_array($type, $target_type_set, true)) {
                $matches = false;
                break;
            }
        }
        if ($matches) {
            return true;
        }

        // Check conversion on the cross product of all
        // type combinations and see if any can cast to
        // any.
        $matches = true;
        foreach ($type_set as $source_type) {
            if (!$source_type->isSubtypeOfAnyTypeInSet($target->getTypeSet(), $code_base)) {
                $matches = false;
                break;
            }
        }
        if ($matches) {
            return true;
        }

        // Only if no source types can be cast to any target
        // types do we say that we cannot perform the cast
        return false;
    }

    /**
     * Check if these types have any possible types in common.
     * (e.g. mixed <-> string, etc.)
     *
     * TODO: Make this work for callable <-> string, etc.
     * @suppress PhanUnreferencedPublicMethod
     */
    public function hasAnyTypeOverlap(CodeBase $code_base, UnionType $other): bool
    {
        // Note that ?int and non-null-mixed are not permitted to cast to each other,
        // but they do have the type overlap of 'int'.
        // So check if types have null in common, then check if the non-null versions of the types can cast to each other.
        if ($this->containsNullableOrUndefined() || $this->isEmpty()) {
            if ($other->containsNullableOrUndefined() || $other->isEmpty()) {
                return true;
            }
            if ($this->isNull()) {
                return false;
            }
        } elseif ($other->isNull()) {
            return false;
        }
        $this_nonnull = $this->withIsNullable(false);
        $other_nonnull = $other->withIsNullable(false);
        return $this_nonnull->canAnyTypeStrictCastToUnionType($code_base, $other_nonnull, false) ||
            $other_nonnull->canAnyTypeStrictCastToUnionType($code_base, $this_nonnull, false);
    }

    /**
     * Check if these types have any possible similar types for weak type comparisons.
     * (e.g. allows false == null checks)
     *
     * @suppress PhanUnreferencedPublicMethod
     */
    public function hasAnyWeakTypeOverlap(UnionType $other, CodeBase $code_base): bool
    {
        return $this->canAnyTypeWeakOverlapUnionType($other, $code_base) || $other->canAnyTypeWeakOverlapUnionType($this, $code_base);
    }

    /**
     * Used for deciding whether to emit PhanTypeMismatchReturnReal.
     * Precondition: The source and target types are non-empty.
     *
     * - Doesn't allow null or void to cast to $other even if with null casting allowed in config,
     *   because that would always throw at runtime
     * - The strictness (e.g. allowing casts from string to bool) depends on the strict_types setting of the Context from which the source type was found.
     */
    public function canCastToDeclaredType(CodeBase $code_base, Context $context, UnionType $other): bool
    {
        if ($this->isNull()) {
            return $other->containsNullableOrMixed();
        }
        $other_type_set = $other->getTypeSet();
        if (!$other_type_set) {
            return true;
        }
        $type_set = $this->type_set;
        if (!$type_set) {
            return true;
        }
        foreach ($type_set as $type) {
            $type = $type->withIsNullable(false);
            foreach ($other_type_set as $other) {
                if ($type->canCastToDeclaredType($code_base, $context, $other)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return bool
     * True if all types in this union are definitely scalars
     * @see scalarTypes
     * @see scalarTypesStrict
     * @suppress PhanUnreferencedPublicMethod
     */
    public function isScalar(): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        return $this->allTypesMatchCallback(static function (Type $type): bool {
            return $type->isScalar();
        });
    }

    /**
     * @return bool
     * True if any types in this union are a printable scalar, or this is the empty union type
     */
    public function hasPrintableScalar(): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        return $this->hasTypeMatchingCallback(static function (Type $type): bool {
            return $type->isPrintableScalar();
        });
    }

    /**
     * @return bool
     * True if any types in this union are a valid operand for a bitwise operator ('|', '&', or '^').
     */
    public function hasValidBitwiseOperand(): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        return $this->hasTypeMatchingCallback(static function (Type $type): bool {
            return $type->isValidBitwiseOperand();
        });
    }

    /**
     * @return bool
     * True if this union has array-like types (is of type array, is
     * a generic array, or implements ArrayAccess).
     */
    public function hasArrayLike(CodeBase $code_base): bool
    {
        return $this->hasTypeMatchingCallback(static function (Type $type) use ($code_base): bool {
            return $type->isArrayLike($code_base);
        });
    }

    /**
     * @return bool
     * True if this union has array-like types (is of type array,
     * or is a generic array)
     */
    public function hasArray(): bool
    {
        return $this->hasTypeMatchingCallback(static function (Type $type): bool {
            return $type instanceof ArrayType;
        });
    }

    /**
     * @return bool
     * True if this union has array-like types (is of type array, is
     * a generic array, is an array shape, or implements ArrayAccess).
     */
    public function hasGenericArray(): bool
    {
        return $this->hasTypeMatchingCallback(static function (Type $type): bool {
            return $type instanceof GenericArrayInterface;
        });
    }

    /**
     * @return bool
     * True if this union contains the ArrayAccess type.
     */
    public function hasArrayAccess(CodeBase $code_base): bool
    {
        return $this->hasTypeMatchingCallback(static function (Type $type) use ($code_base): bool {
            return $type->isArrayAccess($code_base);
        });
    }

    /**
     * Returns the types of this union type that are arrays or are types implementing ArrayAccess.
     *
     * This is useful for code inferring the types of dimensions of a union type.
     */
    public function asArrayOrArrayAccessSubTypes(CodeBase $code_base): UnionType
    {
        $result = UnionType::empty();
        foreach ($this->type_set as $type) {
            if ($type->isArrayOrArrayAccessSubType($code_base)) {
                $result = $result->withType($type);
            }
        }
        return $result;
    }

    /**
     * @return bool
     * True if this union contains the Traversable type.
     * (Calls asExpandedTypes() to check for subclasses of Traversable)
     * @suppress PhanUnreferencedPublicMethod not used right now.
     */
    public function hasTraversable(CodeBase $code_base): bool
    {
        foreach ($this->getTypeSet() as $type) {
            if ($type->isTraversable($code_base)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool
     * True if this union type represents types that are
     * array-like, and nothing else (e.g. can't be null).
     * If any of the array-like types are nullable, this returns false.
     */
    public function isExclusivelyArrayLike(CodeBase $code_base): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        return $this->allTypesMatchCallback(static function (Type $type) use ($code_base): bool {
            return $type->isArrayLike($code_base) && !$type->isNullable();
        });
    }

    /**
     * @return bool
     * True if this union type represents types that are arrays
     * or generic arrays, but nothing else.
     */
    public function isExclusivelyArray(): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        return $this->allTypesMatchCallback(static function (Type $type): bool {
            return $type instanceof ArrayType && !$type->isNullable();
        });
    }

    /**
     * @return UnionType
     * Get the subset of types which are not native
     */
    public function nonNativeTypes(): UnionType
    {
        return $this->makeFromFilter(static function (Type $type): bool {
            return !$type->isNativeType();
        });
    }

    /**
     * A memory efficient way to create a UnionType from a filter operation.
     * If this the filter preserves everything, returns $this instead
     */
    public function makeFromFilter(Closure $cb): UnionType
    {
        return UnionType::of(
            \array_filter($this->type_set, $cb),
            \array_filter($this->real_type_set, $cb)
        );
    }

    /**
     * Returns a list of class FQSENs representing the non-native types
     * associated with this UnionType.
     *
     * @param Context $context
     * The context in which we're resolving this union
     * type.
     *
     * @return Generator
     * @phan-return Generator<FullyQualifiedClassName>
     *
     * @throws CodeBaseException
     * An exception is thrown if a non-native type does not have
     * an associated class
     *
     * @throws IssueException
     * An exception is thrown if static is used as a type outside of an object
     * context
     *
     * TODO: Add a method to ContextNode to directly get FQSEN instead?
     */
    public function asClassFQSENList(
        Context $context
    ): Generator {
        // Iterate over each viable class type to see if any
        // have the constant we're looking for
        foreach ($this->type_set as $class_type) {
            if ($class_type->isNativeType()) {
                continue;
            }
            // Get the class FQSEN
            $class_fqsen = FullyQualifiedClassName::fromType($class_type);

            if ($class_type->isStaticType()) {
                if (!$context->isInClassScope()) {
                    throw new IssueException(
                        Issue::fromType(Issue::ContextNotObject)(
                            $context->getFile(),
                            $context->getLineNumberStart(),
                            [
                                $class_type->getName()
                            ]
                        )
                    );
                }
                yield $class_fqsen;
            } else {
                yield $class_fqsen;
            }
        }
    }

    /**
     * @param CodeBase $code_base
     * The code base in which to find classes
     *
     * @param Context $context
     * The context in which we're resolving this union
     * type.
     *
     * @return Generator<Clazz>
     *
     * A list of classes representing the non-native types
     * associated with this UnionType
     *
     * @throws CodeBaseException
     * An exception is thrown if a non-native type does not have
     * an associated class
     *
     * @throws IssueException
     * An exception is thrown if static is used as a type outside of an object
     * context
     */
    public function asClassList(
        CodeBase $code_base,
        Context $context
    ): Generator {
        // Iterate over each viable class type to see if any
        // have the constant we're looking for
        foreach ($this->getUniqueFlattenedTypeSet() as $class_type) {
            if ($class_type->isNativeType()) {
                continue;
            }
            if ($class_type->isStaticType()) {
                if (!$context->isInClassScope()) {
                    throw new IssueException(
                        Issue::fromType(Issue::ContextNotObject)(
                            $context->getFile(),
                            $context->getLineNumberStart(),
                            [
                                $class_type->getName()
                            ]
                        )
                    );
                }
                yield $context->getClassInScope($code_base);
                continue;
            }

            if ($class_type->isSelfType()) {
                if (!$context->isInClassScope()) {
                    throw new IssueException(
                        Issue::fromType(Issue::ContextNotObject)(
                            $context->getFile(),
                            $context->getLineNumberStart(),
                            [
                                $class_type->getName()
                            ]
                        )
                    );
                }
                yield $context->getClassInScope($code_base);
                continue;
            }

            if (!$class_type->isObjectWithKnownFQSEN()) {
                continue;
            }
            // Get the class FQSEN
            $class_fqsen = FullyQualifiedClassName::fromType($class_type);
            // See if the class exists
            if (!$code_base->hasClassWithFQSEN($class_fqsen)) {
                throw new CodeBaseException(
                    $class_fqsen,
                    "Cannot find class $class_fqsen"
                );
            }
            $class = $code_base->getClassByFQSEN($class_fqsen);
            if (!$class->isPHPInternal() && $code_base->hasClassWithFQSEN($class_fqsen->withAlternateId(1))) {
                self::emitRedefinedClassReferenceWarning($code_base, $context, $class_fqsen);
            }

            yield $class;
        }
    }

    /**
     * Warn about both "$class_fqsen" and the first alternate existing.
     *
     * TODO: Make callers such as ContextNode->asClassList() check if the reference is 'self' and don't emit this if it is?
     */
    public static function emitRedefinedClassReferenceWarning(
        CodeBase $code_base,
        Context $context,
        FullyQualifiedClassName $class_fqsen
    ): void {
        $class = $code_base->getClassByFQSENWithoutHydrating($class_fqsen);
        if ($class->isPHPInternal() || $class->hasSuppressIssue(Issue::RedefinedClassReference)) {
            // already checked if $class was internal.
            return;
        }

        $other_fqsen = $class_fqsen->withAlternateId($class_fqsen->getAlternateId() ? 0 : 1);
        if (!$code_base->hasClassWithFQSEN($other_fqsen)) {
            return;
        }
        $other_class = $code_base->getClassByFQSENWithoutHydrating($other_fqsen);

        if ($other_class->isPHPInternal() || $other_class->hasSuppressIssue(Issue::RedefinedClassReference)) {
            // already checked if $class was internal.
            return;
        }
        Issue::maybeEmit(
            $code_base,
            $context,
            Issue::RedefinedClassReference,
            $context->getLineNumberStart(),
            $class_fqsen->withAlternateId(0),
            $class->getContext()->getFile(),
            $class->getContext()->getLineNumberStart(),
            $other_class->getContext()->getFile(),
            $other_class->getContext()->getLineNumberStart()
        );
    }

    /**
     * Takes `a|b[]|c|d[]|e` and returns `a|c|e`
     *
     * @return UnionType
     * A UnionType with generic array types filtered out
     *
     * @suppress PhanUnreferencedPublicMethod
     */
    public function nonGenericArrayTypes(): UnionType
    {
        return $this->makeFromFilter(static function (Type $type): bool {
            return !($type instanceof GenericArrayInterface);
        });
    }

    /**
     * Takes `a|b[]|c|d[]|e` and returns `b[]|d[]`
     *
     * @return UnionType
     * A UnionType with generic array types kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     */
    public function genericArrayTypes(): UnionType
    {
        return $this->makeFromFilter(static function (Type $type): bool {
            return $type instanceof GenericArrayInterface;
        });
    }

    /**
     * Takes `MyClass|int|array|?object` and returns `MyClass|?object`
     *
     * @return UnionType
     * A UnionType with known object types kept, other types filtered out.
     *
     * @see objectTypesStrict
     */
    public function objectTypes(): UnionType
    {
        return $this->makeFromFilter(static function (Type $type): bool {
            return $type->isObject();
        });
    }

    /**
     * Takes `MyClass|int|array|?object` and returns `MyClass|object`
     *
     * Takes `` and returns `object`
     *
     * Takes `callable|iterable` and returns `callable-object|Traversable`
     */
    public function objectTypesStrict(): UnionType
    {
        return UnionType::of(
            self::castToObjectTypesStrict($this->type_set) ?: [ObjectType::instance(false)],
            self::castToObjectTypesStrict($this->real_type_set) ?: [ObjectType::instance(false)]
        );
    }

    /**
     * Takes `MyClass|int|array|?object` and returns `MyClass|object`
     *
     * Takes `` and returns ``
     *
     * Takes `callable|iterable` and returns `callable-object|Traversable`
     * @suppress PhanUnreferencedPublicMethod called dynamically
     */
    public function objectTypesStrictAllowEmpty(): UnionType
    {
        return UnionType::of(
            self::castToObjectTypesStrict($this->type_set),
            self::castToObjectTypesStrict($this->real_type_set)
        );
    }

    /**
     * @param Type[] $type_list
     * @return list<Type> $type_list
     */
    private static function castToObjectTypesStrict(array $type_list): array
    {
        $result = [];
        foreach ($type_list as $type) {
            $type = $type->asObjectType();
            if ($type) {
                $result[] = $type;
            }
        }
        return $result;
    }

    /**
     * Takes `\MyClass|int|array|?object|\A&\B` and returns `\MyClass|\A&\B`
     *
     * @return UnionType
     * A UnionType with known object types with known FQSENs kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     */
    public function objectTypesWithKnownFQSENs(): UnionType
    {
        return $this->makeFromFilter(static function (Type $type): bool {
            return $type->hasObjectWithKnownFQSEN();
        });
    }

    /**
     * Returns true if objectTypes would be non-empty.
     */
    public function hasObjectTypes(): bool
    {
        return $this->hasTypeMatchingCallback((static function (Type $type): bool {
            return $type->isObject();
        }));
    }

    /**
     * Returns true if at least one type could possibly be an object.
     * E.g. returns true for iterator.
     * NOTE: this returns false for `mixed`
     */
    public function hasPossiblyObjectTypes(): bool
    {
        return $this->hasTypeMatchingCallback((static function (Type $type): bool {
            return $type->isPossiblyObject();
        }));
    }

    /**
     * Returns true if this union type is empty, or if it's possible for any type (or sub-type) of this union type to be able to cast to $class_type
     */
    public function canPossiblyCastToClass(CodeBase $code_base, Type $class_type): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->withIsNullable(false)->canPossiblyCastToClass($code_base, $class_type)) {
                return true;
            }
        }
        return \count($this->type_set) === 0;
    }

    /**
     * Returns true if this union type is non-empty and all types inherit this class/trait/interface.
     */
    public function isExclusivelySubclassesOf(CodeBase $code_base, Type $class_type): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isNullable() || !$type->isSubclassOf($class_type, $code_base)) {
                return false;
            }
        }
        return \count($this->type_set) > 0;
    }
    /**
     * Returns the types for which is_scalar($x) would be true.
     * This means null/nullable is removed.
     * Takes `MyClass|int|?bool|array|?object` and returns `int|bool`
     * Takes `?MyClass` and returns an empty union type.
     *
     * @return UnionType
     * A UnionType with known scalar types kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     */
    public function scalarTypes(): UnionType
    {
        return $this->nonNullableClone()->makeFromFilter(static function (Type $type): bool {
            return $type->isScalar();
        });
    }

    /**
     * A union type after asserting is_scalar($x)
     *
     * @suppress PhanStaticClassAccessWithStaticVariable static variables are safely initialized
     */
    public function scalarTypesStrict(bool $allow_empty = false): UnionType
    {
        // NOTE: this is referenced dynamically in ConditionVisitor
        if ($allow_empty) {
            return UnionType::of(
                self::typeSetToScalarTypesStrict($this->type_set),
                self::typeSetToScalarTypesStrict($this->real_type_set)
            );
        } else {
            static $default;
            if ($default === null) {
                $default = [StringType::instance(false), IntType::instance(false), FloatType::instance(false), BoolType::instance(false)];
            }
            return UnionType::of(
                self::typeSetToScalarTypesStrict($this->type_set) ?: $default,
                self::typeSetToScalarTypesStrict($this->real_type_set) ?: $default
            );
        }
    }

    /**
     * @param Type[] $type_list
     * @return list<Type>
     */
    private static function typeSetToScalarTypesStrict(array $type_list): array
    {
        $result = [];
        foreach ($type_list as $type) {
            $type = $type->asScalarType();
            if ($type instanceof ScalarRawType) {
                array_push($result, ...$type->asIndividualTypeInstances());
                continue;
            }
            if ($type) {
                $result[] = $type;
            }
        }
        return $result;
    }

    /**
     * Returns the types for which is_callable($x) would be true.
     * TODO: Check for __invoke()?
     * Takes `Closure|false` and returns `Closure`
     * Takes `?MyClass` and returns an empty union type.
     *
     * @return UnionType
     * A UnionType with known callable types kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     * @suppress PhanUnreferencedPublicMethod
     */
    public function callableTypes(CodeBase $code_base): UnionType
    {
        return UnionType::of(
            self::castTypeListToCallable($this->type_set, $code_base),
            self::castTypeListToCallable($this->real_type_set, $code_base)
        );
    }

    /**
     * @param Type[] $type_list
     * @return list<Type> possibly containing duplicates
     */
    private static function castTypeListToCallable(array $type_list, CodeBase $code_base): array
    {
        $result = [];
        foreach ($type_list as $type) {
            $type = $type->asCallableType($code_base);
            if ($type) {
                $result[] = $type;
            }
        }
        return $result;
    }

    /**
     * Returns the types for which is_countable($x) would be true.
     * Takes `ArrayObject|false` and returns `ArrayObject`
     * Takes `?(int[])` and returns `int[]`
     * Takes `string` and returns an empty union type.
     *
     * @return UnionType
     * A UnionType with known countable types kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     * @suppress PhanUnreferencedPublicMethod
     * @suppress PhanStaticClassAccessWithStaticVariable static variables are safely initialized
     */
    public function countableTypesStrictCast(CodeBase $code_base, Context $context): UnionType
    {
        static $default_types;
        if (\is_null($default_types)) {
            $default_types = [ArrayType::instance(false), Type::countableInstance()];
        }
        return UnionType::of(
            self::castTypeListToCountable($code_base, $this->type_set, $context) ?: $default_types,
            self::castTypeListToCountable($code_base, $this->real_type_set, $context) ?: $default_types
        );
    }

    /**
     * @param Type[] $type_list
     * @return list<Type> a list of countable subclasses and array types, possibly containing duplicates
     * @internal
     */
    public static function castTypeListToCountable(CodeBase $code_base, array $type_list, Context $context): array
    {
        $result = [];
        $countable = Type::countableInstance();
        foreach ($type_list as $type) {
            if ($type instanceof IterableType) {
                $result[] = $type->asArrayType();
                if ($type->isPossiblyObject()) {
                    $object_type = $type->withIsNullable(false)->asObjectType();
                    if ($object_type) {
                        // TODO: Convert iterable with template types to Traversable with template types
                        $result[] = IntersectionType::createFromTypes([$object_type, $countable], $code_base, $context);
                    } else {
                        $result[] = $countable;
                    }
                }
                continue;
            } elseif ($type->hasObjectWithKnownFQSEN()) {
                $type = $type->withIsNullable(false);
                if ($type->isSubtypeOf($countable, $code_base)) {
                    $result[] = $type;
                    continue;
                }
                if (!$type->canCastToDeclaredType($code_base, $context, $countable)) {
                    continue;
                }
                $result[] = IntersectionType::createFromTypes([$type, $countable], $code_base, $context);
            } else {
                if ($type->isPossiblyObject()) {
                    // e.g. object/mixed/callable-object can also be Countable
                    $result[] = Type::countableInstance();
                }
                if ($type instanceof MixedType) {
                    $result[] = ArrayType::instance(false);
                }
            }
        }
        return $result;
    }

    /**
     * Returns the types for which is_int($x) would be true.
     *
     * @return UnionType
     * A UnionType with known int types kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     * @suppress PhanUnreferencedPublicMethod
     */
    public function intTypes(): UnionType
    {
        return UnionType::of(
            self::castTypeListToInt($this->type_set),
            self::castTypeListToInt($this->real_type_set)
        );
    }

    /**
     * @param Type[] $type_set
     * @return list<Type>
     */
    private static function castTypeListToInt(array $type_set): array
    {
        $result = [];
        foreach ($type_set as $type) {
            if ($type instanceof IntType) {
                $result[] = $type->withIsNullable(false);
            } elseif ($type instanceof MixedType) {
                $result[] = IntType::instance(false);
            }
        }
        return $result;
    }

    /**
     * Returns the types for which is_float($x) would be true.
     *
     * @return UnionType
     * A UnionType with known float types kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     * @suppress PhanUnreferencedPublicMethod
     */
    public function floatTypes(): UnionType
    {
        return UnionType::of(
            self::castTypeListToFloat($this->type_set),
            self::castTypeListToFloat($this->real_type_set)
        );
    }

    /**
     * @param Type[] $type_set
     * @return list<Type>
     */
    private static function castTypeListToFloat(array $type_set): array
    {
        $result = [];
        foreach ($type_set as $type) {
            if ($type instanceof FloatType) {
                $result[] = $type->withIsNullable(false);
            } elseif ($type instanceof MixedType) {
                $result[] = FloatType::instance(false);
            }
        }
        return $result;
    }

    /**
     * Returns the types for which is_string($x) would be true.
     *
     * @return UnionType
     * A UnionType with known string types kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     * @suppress PhanUnreferencedPublicMethod
     */
    public function stringTypes(): UnionType
    {
        return UnionType::of(
            self::castTypeListToString($this->type_set),
            self::castTypeListToString($this->real_type_set)
        );
    }

    /**
     * Returns true if this is non-empty and all types in this type set are strings.
     */
    public function isExclusivelyStringTypes(): bool
    {
        return $this->allTypesMatchCallback(static function (Type $type): bool {
            return $type instanceof StringType;
        });
    }

    /**
     * @param Type[] $type_set
     * @return list<Type>
     */
    private static function castTypeListToString(array $type_set): array
    {
        $result = [];
        foreach ($type_set as $type) {
            if ($type instanceof StringType) {
                $result[] = $type->withIsNullable(false);
            } elseif ($type instanceof CallableType) {
                $result[] = CallableStringType::instance(false);
            } elseif ($type instanceof MixedType) {
                $result[] = $type instanceof NonEmptyMixedType ? NonEmptyStringType::instance(false) : StringType::instance(false);
            }
        }
        return $result;
    }

    /**
     * Returns the types for which class_exists($x) would be true.
     *
     * @return UnionType
     * A UnionType with known class string types kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     * @suppress PhanUnreferencedPublicMethod
     */
    public function classStringTypes(): UnionType
    {
        return UnionType::of(
            self::castTypeListToClassString($this->type_set),
            self::castTypeListToClassString($this->real_type_set)
        );
    }

    /**
     * @param Type[] $type_set
     * @return list<Type>
     */
    private static function castTypeListToClassString(array $type_set): array
    {
        $result = [];
        $is_possibly_string = false;
        foreach ($type_set as $type) {
            if ($type instanceof LiteralStringType) {
                if (!\preg_match(FullyQualifiedClassName::VALID_CLASS_REGEX, $type->getValue()) && !\preg_match('/^\\\\?oci-(lob|collection)$/iD', $type->getValue())) {
                    continue;
                }
                $result[] = $type->withIsNullable(false);
                continue;
            }
            if ($type instanceof StringType || $type instanceof MixedType) {
                $is_possibly_string = true;
            }
        }
        if ($is_possibly_string) {
            $result[] = ClassStringType::instance(false);
        }
        return $result;
    }

    /**
     * Returns the types for which method_exists($x, ...) could be true.
     *
     * @return UnionType
     * A UnionType with known class string types kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     * @suppress PhanUnreferencedPublicMethod
     */
    public function classStringOrObjectTypes(): UnionType
    {
        return UnionType::of(
            self::castTypeListToClassStringOrObject($this->type_set),
            self::castTypeListToClassStringOrObject($this->real_type_set)
        );
    }

    /**
     * @param Type[] $type_set
     * @return list<Type>
     */
    private static function castTypeListToClassStringOrObject(array $type_set): array
    {
        return \array_merge(self::castTypeListToClassString($type_set), self::castToObjectTypesStrict($type_set));
    }

    /**
     * Returns the types for which is_numeric($x) is possibly true.
     *
     * @return UnionType
     * A UnionType with known numeric types kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     * @suppress PhanUnreferencedPublicMethod
     */
    public function numericTypes(): UnionType
    {
        // TODO: Replace mixed with int|string|float in ConditionVisitor
        return UnionType::of(
            self::toNumericTypes($this->type_set),
            self::toNumericTypes($this->real_type_set)
        );
    }

    /**
     * @param list<Type> $type_set
     * @return list<Type> the types that can be numeric
     */
    private static function toNumericTypes(array $type_set): array
    {
        $result = [];
        foreach ($type_set as $type) {
            if ($type->isPossiblyNumeric()) {
                if ($type instanceof MixedType) {
                    return UnionType::typeSetFromString('int|string|float');
                }
                $result[] = $type->withIsNullable(false);
            }
        }
        return $result;
    }

    /**
     * Returns true if this has one or more callable types
     * TODO: Check for __invoke()?
     * Takes `Closure|false` and returns true
     * Takes `?MyClass` and returns false
     *
     * @return bool
     * A UnionType with known callable types kept, other types filtered out.
     *
     * @see self::callableTypes()
     *
     * @suppress PhanUnreferencedPublicMethod
     */
    public function hasCallableType(CodeBase $code_base): bool
    {
        return $this->hasTypeMatchingCallback(static function (Type $type) use ($code_base): bool {
            return $type->isCallable($code_base);
        });
    }

    /**
     * Returns true if every type in this type is callable.
     * TODO: Check for __invoke()?
     *
     * Takes `callable` and returns true
     * Takes `callable|false` and returns false
     *
     * @return bool
     * A UnionType with known callable types kept, other types filtered out.
     *
     * @see nonGenericArrayTypes
     * @suppress PhanUnreferencedPublicMethod not used right now.
     */
    public function isExclusivelyCallable(CodeBase $code_base): bool
    {
        return $this->allTypesMatchCallback(static function (Type $type) use ($code_base): bool {
            return $type->isCallable($code_base);
        });
    }

    /**
     * @return bool is each of the types in this type bool, false, or true?
     * This also returns false if any type is nullable.
     */
    public function isExclusivelyBoolTypes(): bool
    {
        if ($this->isEmpty()) {
            return false;
        }
        foreach ($this->type_set as $type) {
            if (!$type->isInBoolFamily() || $type->isNullable()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Takes `a|b[]|c|d[]|e|array|ArrayAccess` and returns `a|c|e|ArrayAccess`
     *
     * @return UnionType
     * A UnionType with generic types(as well as the non-generic type `array`)
     * filtered out.
     *
     * @see nonGenericArrayTypes
     */
    public function nonArrayTypes(): UnionType
    {
        return $this->makeFromFilter(
            static function (Type $type): bool {
                return !($type instanceof ArrayType);
            }
        );
    }

    /**
     * Takes `a|b[]|c|d[]|e|f[]|ArrayAccess` and returns `b[]|f[]`
     *
     * @return UnionType
     * A UnionType with non-array types filtered out
     *
     * @see self::nonArrayTypes()
     */
    public function arrayTypes(): UnionType
    {
        return $this->makeFromFilter(
            static function (Type $type): bool {
                return $type instanceof ArrayType;
            }
        );
    }

    /**
     * This is the union type Phan infers from assert(is_array($x))
     * Converts iterable<key,value> to array<key, value>
     * Takes `A[]|ArrayAccess` and returns `A[]`
     * Takes `callable` and returns `callable-array`
     * Takes `` and returns `array`
     */
    public function arrayTypesStrictCast(): UnionType
    {
        return UnionType::of(
            self::castToArrayTypesStrict($this->type_set) ?: [ArrayType::instance(false)],
            self::castToArrayTypesStrict($this->real_type_set) ?: [ArrayType::instance(false)]
        );
    }

    /**
     * This is the union type Phan infers from `assert(is_array($x))`
     * Converts `iterable<key,value>` to `array<key, value>`
     * Takes `A[]|ArrayAccess` and returns `A[]`
     * Takes `callable` and returns `callable-array`
     * Takes `` and returns ``
     * @suppress PhanUnreferencedPublicMethod called dynamically
     */
    public function arrayTypesStrictCastAllowEmpty(): UnionType
    {
        return UnionType::of(
            self::castToArrayTypesStrict($this->type_set),
            self::castToArrayTypesStrict($this->real_type_set)
        );
    }

    /**
     * @param Type[] $type_list
     * @return list<Type>
     */
    private static function castToArrayTypesStrict(array $type_list): array
    {
        $result = [];
        foreach ($type_list as $type) {
            $type = $type->asArrayType();
            if ($type) {
                $result[] = $type;
            }
        }
        return $result;
    }

    /**
     * This is the union type Phan infers from `assert(array_is_list($x))`
     * Converts `iterable<key,value>` to `list<value>`
     * Takes `` and returns `list`
     * @suppress PhanUnreferencedPublicMethod called dynamically
     */
    public function listTypesStrictCast(): UnionType
    {
        return UnionType::of(
            self::castToListTypesStrict($this->type_set) ?: UnionType::typeSetFromString('list'),
            self::castToListTypesStrict($this->real_type_set) ?: UnionType::typeSetFromString('list')
        );
    }

    /**
     * This is the union type Phan infers from `assert(array_is_list($x))`
     * Converts `iterable<key,value>` to `list<value>`
     * Takes `A[]|ArrayAccess` and returns `list<A>`
     * Takes `callable` and returns `list<object|string>` (callable arrays permit any key order)
     * Takes `` and returns ``
     * @suppress PhanUnreferencedPublicMethod called dynamically
     */
    public function listTypesStrictCastAllowEmpty(): UnionType
    {
        return UnionType::of(
            self::castToListTypesStrict($this->type_set),
            self::castToListTypesStrict($this->real_type_set)
        );
    }

    /**
     * @param Type[] $type_list
     * @return list<Type>
     */
    private static function castToListTypesStrict(array $type_list): array
    {
        $result = [];
        foreach ($type_list as $type) {
            $type = $type->asArrayType();
            if ($type instanceof ArrayType) {
                foreach ($type->castToListTypes()->getTypeSet() as $sub_type) {
                    $result[] = $sub_type;
                }
            }
        }
        return $result;
    }

    /**
     * @return bool
     * True if this is non-empty and exclusively generic array types such as array<int,\stdClass> or array{x:int}
     *
     * @see UnionType::isArray to check for all possible arrays
     * @suppress PhanUnreferencedPublicMethod
     */
    public function isGenericArray(): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        return $this->allTypesMatchCallback(static function (Type $type): bool {
            return $type instanceof GenericArrayInterface;
        });
    }

    /**
     * @return bool
     * True if this is non-empty and exclusively array types
     */
    public function isArray(): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        return $this->allTypesMatchCallback(static function (Type $type): bool {
            return $type instanceof ArrayType;
        });
    }

    /**
     * @return bool
     * True if this is non-empty and exclusively object types
     */
    public function isObject(): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        return $this->allTypesMatchCallback(static function (Type $type): bool {
            return $type->isObject();
        });
    }

    /**
     * @return bool
     * True if any of the types in this UnionType made $matcher_callback return true
     */
    public function hasTypeMatchingCallback(Closure $matcher_callback): bool
    {
        foreach ($this->type_set as $type) {
            if ($matcher_callback($type)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool
     * True if any of the types in the real type set of this UnionType made $matcher_callback return true.
     *
     * Callers should check hasRealTypeSet. This returns false if there are no real types.
     */
    public function hasRealTypeMatchingCallback(Closure $matcher_callback): bool
    {
        foreach ($this->real_type_set as $type) {
            if ($matcher_callback($type)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Equivalent to hasTypeMatchingCallback || hasRealTypeMatchingCallback
     */
    public function hasPhpdocOrRealTypeMatchingCallback(Closure $matcher_callback): bool
    {
        foreach ($this->type_set as $type) {
            if ($matcher_callback($type)) {
                return true;
            }
        }
        foreach ($this->real_type_set as $type) {
            if ($matcher_callback($type)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param Closure(Type): bool $matcher_callback
     * @return bool
     * True if each of the types in this UnionType made $matcher_callback return true
     */
    public function allTypesMatchCallback(Closure $matcher_callback): bool
    {
        foreach ($this->type_set as $type) {
            if (!$matcher_callback($type)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return Type|false
     * Returns the first type in this UnionType made $matcher_callback return true
     * @suppress PhanUnreferencedPublicMethod
     */
    public function findTypeMatchingCallback(Closure $matcher_callback)
    {
        foreach ($this->type_set as $type) {
            if ($matcher_callback($type)) {
                return $type;
            }
        }
        return false;
    }

    /**
     * Takes `a|b[]|c|d[]|e|Traversable<f,g>` and returns `int|string|f`
     *
     * Takes `array{field:int,other:stdClass}` and returns `string`
     *
     * @param CodeBase $code_base (for detecting the iterable value types of `class MyIterator extends Iterator`)
     */
    public function iterableKeyUnionType(CodeBase $code_base): UnionType
    {
        // This is frequently called, and has been optimized
        $new_type_builder = new UnionTypeBuilder();
        foreach ($this->type_set as $type) {
            $key_union_type = $type->iterableKeyUnionType($code_base);
            if ($key_union_type === null) {
                // Does not have iterable values
                continue;
            }
            $new_type_builder->addUnionType($key_union_type);
        }
        $new_real_type_builder = new UnionTypeBuilder();
        foreach ($this->real_type_set as $type) {
            if ($type instanceof ScalarType) {
                // skip false, null, etc.
                continue;
            }
            $key_union_type = $type->iterableKeyUnionType($code_base);
            if ($key_union_type === null) {
                // Not certain of the real type. It could be a subclass of Traversable with unknown keys.
                $new_real_type_builder->clearTypeSet();
                break;
            }
            // TODO: Instead of coercing string to int here, update the real type when the array is assigned to,
            // if the string is a non-literal.
            //
            // Note that array shapes with 0 elements do not have types. (tests/files/src/0461_array_key_exists.php)
            if ($type instanceof ArrayType && $type->isPossiblyTruthy()) {
                if ($key_union_type->isEmpty()) {
                    $key_union_type = UnionType::fromFullyQualifiedPHPDocString('int|string');
                } else {
                    foreach ($key_union_type->getTypeSet() as $key_type) {
                        if ($key_type instanceof StringType && $key_type->isPossiblyNumeric()) {
                            // Numeric literals such as `'0'` cast to 0 when inserted as array keys.
                            $new_real_type_builder->addType(IntType::instance(false));
                            break;
                        }
                    }
                }
            }
            $new_real_type_builder->addUnionType($key_union_type);
        }
        $type_set = $new_type_builder->getTypeSet();
        $real_type_set = $new_real_type_builder->getTypeSet();
        if (!$type_set && $real_type_set) {
            $type_set = UnionType::typeSetFromString('mixed');
        }

        $result = UnionType::of($type_set, $real_type_set);
        return $result;
    }

    /**
     * Takes `a|b[]|c|d[]|e|Traversable<f,g>` and returns `b|d|g`
     *
     * Takes `array{field:int,other:string}` and returns `int|string`
     *
     * @param CodeBase $code_base (for detecting the iterable value types of `class MyIterator extends Iterator`)
     * @suppress PhanStaticClassAccessWithStaticVariable static variables are safely initialized
     */
    public function iterableValueUnionType(CodeBase $code_base): UnionType
    {
        // This is frequently called, and has been optimized
        // TODO: Support real types if the type set is exclusively real iterable types
        $builder = new UnionTypeBuilder();
        $type_set = $this->type_set;
        foreach ($type_set as $type) {
            $element_type = $type->iterableValueUnionType($code_base);
            if ($element_type === null) {
                // Does not have iterable values
                continue;
            }
            $builder->addUnionType($element_type);
        }
        $real_builder = new UnionTypeBuilder();
        foreach ($this->real_type_set as $type) {
            if ($type instanceof ScalarType) {
                // skip false, null, etc.
                continue;
            }
            $element_type = $type->iterableValueUnionType($code_base);
            if ($element_type === null) {
                // Not certain of the real type. It could be a subclass of Traversable with unknown values.
                $real_builder->clearTypeSet();
                break;
            }
            $real_builder->addUnionType($element_type);
        }

        static $array_type_nonnull = null;
        static $array_type_nullable = null;
        static $mixed_type = null;
        static $null_type = null;
        if ($array_type_nonnull === null) {
            $array_type_nonnull = ArrayType::instance(false);
            $array_type_nullable = ArrayType::instance(true);
            $mixed_type = MixedType::instance(false);
            $null_type = NullType::instance(false);
        }

        // If array is in there, then it can be any type
        if (\in_array($array_type_nonnull, $type_set, true)) {
            $builder->addType($mixed_type);
        } elseif (\in_array($mixed_type, $type_set, true)
            || \in_array($array_type_nullable, $type_set, true)
        ) {
            // Same for mixed
            $builder->addType($mixed_type);
        }

        return UnionType::of($builder->getTypeSet(), $real_builder->getTypeSet());
    }

    /**
     * Takes `a|b[]|c|d[]|e` and returns `b|d`
     * Takes `array{field:int,other:string}` and returns `int|string`
     *
     * @param bool $add_real_types if true, this adds the real types that would be possible for `$x[$offset]`
     * @suppress PhanStaticClassAccessWithStaticVariable static variables are safely initialized
     */
    public function genericArrayElementTypes(bool $add_real_types, CodeBase $code_base): UnionType
    {
        // This is frequently called, and has been optimized
        $result = [];
        $type_set = $this->type_set;
        foreach ($type_set as $type) {
            if ($type instanceof GenericArrayInterface) {
                if ($type instanceof GenericArrayType) {
                    $result[] = $type->genericArrayElementType();
                } else {
                    foreach ($type->genericArrayElementUnionType()->getTypeSet() as $inner_type) {
                        $result[] = $inner_type;
                    }
                }
            }
        }

        static $array_type_nonnull = null;
        static $array_type_nullable = null;
        static $mixed_type = null;
        static $null_type = null;
        if ($array_type_nonnull === null) {
            $array_type_nonnull = ArrayType::instance(false);
            $array_type_nullable = ArrayType::instance(true);
            $mixed_type = MixedType::instance(false);
            $null_type = NullType::instance(false);
        }

        if (\in_array($array_type_nullable, $type_set, true)) {
            // TODO: More consistency in what causes this check to infer null
            $result[] = $mixed_type;
            $result[] = $null_type;
        } elseif (\in_array($array_type_nonnull, $type_set, true) ||
            // If array is in there, then it can be any type
            \in_array($mixed_type, $type_set, true)
        ) {
            $result[] = $mixed_type;
        }
        if ($add_real_types && $result && $this->real_type_set) {
            return UnionType::of($result, self::computeRealElementTypesForDimAccess($this->real_type_set, $code_base));
        }

        return UnionType::of($result);
    }

    /**
     * Returns the real types seen for an array dim access expression such as `$x = expr[offset]`
     * @param non-empty-list<Type> $real_type_set the set of types of expr
     * @return list<Type> possibly empty, possibly with duplicates. These types are nullable to indicate that array accesses can fail.
     * @internal
     */
    public static function computeRealElementTypesForDimAccess(array $real_type_set, CodeBase $code_base): array
    {
        $result = [];
        foreach ($real_type_set as $type) {
            if ($type instanceof StringType) {
                // Note that 'var'[9] will still be a string (the empty string) if the offset is invalid.
                // So will 'var'['not an int']
                $result[] = StringType::instance(false);
                continue;
            }
            if ($type->isPossiblyObject()) {
                // e.g. Mixed, \MyClass, iterable, etc.
                // We don't know some of the real types, so return the empty list as the set of real types.
                //
                // TODO: use offsetGet signature?
                return [];
            }
            if (!$type->isArrayLike($code_base)) {
                continue;
            }
            if (!$type instanceof ArrayType) {
                return [];
            }
            $new_types = $type->genericArrayElementUnionType()->getTypeSet();
            if (!$new_types) {
                return [];
            }
            foreach ($new_types as $element_type) {
                if ($element_type instanceof MixedType) {
                    return [];
                }
                $result[] = $element_type->withIsNullable(true);
            }
        }
        return $result;
    }

    /**
     * Returns the real types seen for an array destructuring expression such as `[$x] = expr`
     * @param non-empty-list<Type> $real_type_set the set of types of expr
     * @return list<Type> possibly empty, possibly with duplicates. These types are nullable to indicate that array accesses can fail.
     * @internal
     */
    public static function computeRealElementTypesForDestructuringAccess(array $real_type_set, CodeBase $code_base): array
    {
        $result = [];
        foreach ($real_type_set as $type) {
            if ($type instanceof StringType) {
                // Note that 'var'[9] will still be a string (the empty string) if the offset is invalid.
                // So will 'var'['not an int']
                $result[] = NullType::instance(false);
                continue;
            }
            if ($type->isPossiblyObject()) {
                // e.g. Mixed, \MyClass, iterable, etc.
                // We don't know some of the real types, so return the empty list as the set of real types.
                //
                // TODO: Use iterableValueUnionType?
                return [];
            }
            if (!$type->isArrayLike($code_base)) {
                continue;
            }
            if (!$type instanceof ArrayType) {
                return [];
            }
            $new_types = $type->genericArrayElementUnionType()->getTypeSet();
            if (!$new_types) {
                return [];
            }
            foreach ($new_types as $element_type) {
                if ($element_type instanceof MixedType) {
                    return [];
                }
                $result[] = $element_type->withIsNullable(true);
            }
        }
        return $result;
    }

    /**
     * Takes `b|d[]` and returns `b[]|d[][]`
     *
     * @param int $key_type
     * Corresponds to the type of the array keys. Set this to a GenericArrayType::KEY_* constant.
     *
     * @return UnionType
     * The subset of types in this
     *
     * TODO: Add a variant that will convert mixed to array<int,mixed> instead of array?
     */
    public function elementTypesToGenericArray(int $key_type): UnionType
    {
        $parts = \array_map(static function (Type $type) use ($key_type): ArrayType {
            if ($type instanceof MixedType) {
                return ArrayType::instance(false);
            }
            return GenericArrayType::fromElementType($type, false, $key_type);
        }, $this->type_set);
        if (\count($parts) <= 1) {
            // @phan-suppress-next-line PhanPossiblyNonClassMethodCall
            return \count($parts) === 1 ? \reset($parts)->asPHPDocUnionType() : self::$empty_instance;
        }
        return new UnionType($parts, false, []);
    }

    /**
     * @param Closure(Type):Type $closure
     * A closure mapping `Type` to `Type`
     *
     * @return UnionType
     * A new UnionType with each type mapped through the
     * given closure
     */
    public function asMappedUnionType(Closure $closure): UnionType
    {
        $new_type_set = \array_map($closure, $this->type_set);
        $new_real_type_set = \array_map($closure, $this->real_type_set);
        if ($new_type_set === $this->type_set && $new_real_type_set === $this->real_type_set) {
            return $this;
        }
        return UnionType::of(
            $new_type_set,
            $new_real_type_set
        );
    }

    /**
     * @param Closure(Type):(list<Type>) $closure
     * A closure mapping `Type` to a list of types for that type
     *
     * @return UnionType
     * A new UnionType with each type mapped to a list of types
     * through the given closure.
     */
    public function asMappedListUnionType(Closure $closure): UnionType
    {
        // In php 7.3, this could be replaced with https://www.php.net/array_push
        $new_type_set = [];
        foreach ($this->type_set as $type) {
            foreach ($closure($type) as $new_type) {
                $new_type_set[] = $new_type;
            }
        }
        $new_real_type_set = [];
        foreach ($this->real_type_set as $type) {
            foreach ($closure($type) as $new_type) {
                $new_real_type_set[] = $new_type;
            }
        }
        if ($new_type_set === $this->type_set && $new_real_type_set === $this->real_type_set) {
            return $this;
        }
        return UnionType::of(
            $new_type_set,
            $new_real_type_set
        );
    }

    /**
     * @param Closure(UnionType):UnionType $closure
     */
    public function withMappedElementTypes(Closure $closure): UnionType
    {
        return $this->asMappedUnionType(static function (Type $type) use ($closure): Type {
            if ($type instanceof ArrayShapeType) {
                $field_types = \array_map($closure, $type->getFieldTypes());
                $result = ArrayShapeType::fromFieldTypes($field_types, $type->isNullable());
                return $result;
            } elseif ($type instanceof GenericArrayType) {
                $element_types = $closure($type->genericArrayElementType()->asPHPDocUnionType());
                if ($element_types->typeCount() !== 1) {
                    $element_type = MixedType::instance(false);
                } else {
                    $element_type = $element_types->getTypeSet()[0];
                }
                return GenericArrayType::fromElementType($element_type, $type->isNullable(), $type->getKeyType());
            }
            return ArrayType::instance(false);
        });
    }

    /**
     * @param int $key_type
     * Corresponds to the type of the array keys. Set this to a GenericArrayType::KEY_* constant.
     *
     * @return UnionType
     * Get a new type for each type in this union which is
     * the generic array version of this type. For instance,
     * 'int|float' will produce 'int[]|float[]'.
     *
     * If $this is an empty UnionType, this method will produce an empty UnionType
     */
    public function asGenericArrayTypes(int $key_type): UnionType
    {
        return $this->asMappedUnionType(
            static function (Type $type) use ($key_type): Type {
                return $type->asGenericArrayType($key_type);
            }
        );
    }

    /**
     * Get a new type for each type in this union which is
     * the generic array version of this type. For instance,
     * 'int|float' will produce 'list<int>|list<float>'.
     *
     * If $this is an empty UnionType, this method will produce an empty UnionType
     */
    public function asListTypes(): UnionType
    {
        return $this->asMappedUnionType(
            // TODO: Fix https://github.com/phan/phan/issues/3755 and change return type to GenericArrayType
            static function (Type $type): Type {
                return ListType::fromElementType($type, false);
            }
        );
    }

    /**
     * @return UnionType
     * Get a new type for each type in this union which is
     * the generic array version of this type. For instance,
     * 'int|float' will produce 'int[]|float[]'.
     *
     * If $this is an empty UnionType, this method will produce 'array'
     * @suppress PhanStaticClassAccessWithStaticVariable array elements are initialized without depending on inherited class
     */
    public function asNonEmptyGenericArrayTypes(int $key_type): UnionType
    {
        static $cache = [];
        $type = ($cache[$key_type] ?? ($cache[$key_type] = MixedType::instance(false)->asGenericArrayType($key_type)));
        if (\count($this->type_set) === 0) {
            return $type->asRealUnionType();
        }
        $result = $this->asMappedUnionType(
            static function (Type $type) use ($key_type): Type {
                return $type->asGenericArrayType($key_type);
            }
        );
        if (!$result->hasRealTypeSet()) {
            return $result->withRealType($type);
        }
        return $result;
    }

    /**
     * @return UnionType
     * Get a new type for each type in this union which is
     * the associative array version of this type. For instance,
     * 'int|float' will produce 'associative-array<int>|associative-array<float>'.
     *
     * If $this is an empty UnionType, this method will produce 'associative-array<mixed>'
     * @suppress PhanStaticClassAccessWithStaticVariable array elements are initialized without depending on inherited class
     */
    public function asNonEmptyAssociativeArrayTypes(int $key_type): UnionType
    {
        static $cache = [];
        $type = ($cache[$key_type] ?? ($cache[$key_type] = AssociativeArrayType::fromElementType(MixedType::instance(false), false, $key_type)));
        if (\count($this->type_set) === 0) {
            return $type->asRealUnionType();
        }
        $result = $this->asMappedUnionType(
            static function (Type $type) use ($key_type): Type {
                return NonEmptyAssociativeArrayType::fromElementType($type, false, $key_type);
            }
        );
        if (!$result->hasRealTypeSet()) {
            return $result->withRealType($type);
        }
        return $result;
    }

    /**
     * @return UnionType
     * Get a new type for each type in this union which is
     * the generic array version of this type. For instance,
     * 'int|float' will produce 'list<int>|list<float>'.
     *
     * If $this is an empty UnionType, this method will produce 'list<mixed>'
     * @suppress PhanStaticClassAccessWithStaticVariable static variables are safely initialized
     */
    public function asNonEmptyListTypes(): UnionType
    {
        static $list_of_mixed_type = null;
        if ($list_of_mixed_type === null) {
            $list_of_mixed_type = ListType::fromElementType(MixedType::instance(false), false);
        }
        if (\count($this->type_set) === 0) {
            return $list_of_mixed_type->asRealUnionType();
        }
        $result = $this->asMappedUnionType(
            static function (Type $element_type): Type {
                return ListType::fromElementType($element_type, false);
            }
        );
        if (!$result->hasRealTypeSet()) {
            return $result->withRealType($list_of_mixed_type);
        }
        return $result;
    }

    /**
     * @param CodeBase $code_base
     * The code base to use in order to find super classes, etc.
     *
     * @param $recursion_depth
     * This thing has a tendency to run-away on me. This tracks
     * how bad I messed up by seeing how far the expanded types
     * go
     *
     * @return UnionType
     * Expands all class types to all inherited classes returning
     * a superset of this type.
     */
    public function asExpandedTypes(
        CodeBase $code_base,
        int $recursion_depth = 0
    ): UnionType {
        // TODO: Preserve the original real types without expanding them?
        if ($recursion_depth >= 12) {
            throw new RecursionDepthException("Recursion has gotten out of hand: " . Frame::getExpandedTypesDetails());
        }

        $type_set = $this->type_set;
        if (\count($type_set) === 0) {
            return self::$empty_instance;
        } elseif (\count($type_set) === 1) {
            // @phan-suppress-next-line PhanPossiblyNonClassMethodCall
            return \reset($type_set)->asExpandedTypes(
                $code_base,
                $recursion_depth + 1
            )->withRealTypeSet($this->real_type_set);
        }
        // 2 or more union types to merge

        $builder = new UnionTypeBuilder();
        foreach ($type_set as $type) {
            $builder->addUnionType(
                $type->asExpandedTypes(
                    $code_base,
                    $recursion_depth + 1
                )
            );
        }
        return UnionType::of($builder->getTypeSet(), $this->real_type_set);
    }

    /**
     * @param CodeBase $code_base
     * The code base to use in order to find super classes, etc.
     *
     * @param $recursion_depth
     * This thing has a tendency to run-away on me. This tracks
     * how bad I messed up by seeing how far the expanded types
     * go
     *
     * @return UnionType
     * Expands all class types to all inherited classes returning
     * a superset of this type, not removing template types
     */
    public function asExpandedTypesPreservingTemplate(
        CodeBase $code_base,
        int $recursion_depth = 0
    ): UnionType {
        if ($recursion_depth >= 12) {
            throw new RecursionDepthException("Recursion has gotten out of hand: " . Frame::getExpandedTypesDetails());
        }

        $type_set = $this->type_set;
        if (\count($type_set) === 0) {
            return self::$empty_instance;
        } elseif (\count($type_set) === 1) {
            // @phan-suppress-next-line PhanPossiblyNonClassMethodCall
            return \reset($type_set)->asExpandedTypesPreservingTemplate(
                $code_base,
                $recursion_depth + 1
            )->withRealTypeSet($this->real_type_set);
        }
        // 2 or more union types to merge

        $builder = new UnionTypeBuilder();
        foreach ($type_set as $type) {
            $builder->addUnionType(
                $type->asExpandedTypesPreservingTemplate(
                    $code_base,
                    $recursion_depth + 1
                )
            );
        }
        return UnionType::of($builder->getTypeSet(), $this->real_type_set);
    }

    /**
     * Remove all types with the same FQSENs as $template_union_type with the types.
     * Then, return this with $template_union_type added.
     */
    public function replaceWithTemplateTypes(UnionType $template_union_type): UnionType
    {
        if ($template_union_type->isEmpty()) {
            return $this;
        }
        $new_type_set = $this->type_set;
        foreach ($this->type_set as $i => $type) {
            // TODO: Handle recursion
            if ($template_union_type->hasTypeWithFQSEN($type)) {
                unset($new_type_set[$i]);
                if ($type->isNullable()) {
                    // Preserve nullable
                    $template_union_type = $template_union_type->nullableClone();
                }
            }
        }
        $new_type_set = \array_merge($new_type_set, $template_union_type->getTypeSet());
        return UnionType::of($new_type_set, $this->real_type_set);
    }

    /**
     * Check if at least one type in this union type has the same FQSEN as $other
     * Returns false if $other is not an object type.
     */
    public function hasTypeWithFQSEN(Type $other): bool
    {
        if (!$other->isObjectWithKnownFQSEN()) {
            return false;
        }
        foreach ($this->type_set as $type) {
            if ($type->hasSameNamespaceAndName($other) && $type->isObjectWithKnownFQSEN()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Filters the types with the same FQSEN as $other
     * @suppress PhanUnreferencedPublicMethod
     */
    public function getTypesWithFQSEN(Type $other): UnionType
    {
        if (!$other->isObjectWithKnownFQSEN()) {
            return UnionType::empty();
        }
        $result = $this;
        foreach ($this->type_set as $type) {
            if ($type->hasSameNamespaceAndName($other) && $type->isObjectWithKnownFQSEN()) {
                $result = $result->withoutType($type);
            }
        }
        return $result;
    }

    /**
     * As per the Serializable interface
     *
     * @return string
     * A serialized representation of this type
     *
     * @see \Serializable
     */
    public function serialize(): string
    {
        if ($this->real_type_set) {
            return (string)$this . "\x00" . \implode('|', $this->real_type_set);
        }
        return (string)$this;
    }

    /**
     * As per the Serializable interface
     *
     * @param string $serialized
     * A serialized UnionType
     *
     * @see \Serializable
     * @suppress PhanAccessReadOnlyProperty this unserializes
     */
    public function unserialize($serialized): void
    {
        $i = \strpos($serialized, "\x00");
        if ($i !== false) {
            $result = UnionType::fromFullyQualifiedPHPDocAndRealString(
                substr($serialized, 0, $i),
                (string)substr($serialized, $i + 1)
            );
            $this->type_set = $result->getTypeSet();
            $this->real_type_set = $result->getRealTypeSet();
            return;
        }
        $this->type_set = UnionType::fromFullyQualifiedPHPDocString($serialized)->getTypeSet();
    }

    /**
     * @return array{0: string, 1?: string}
     */
    public function __serialize(): array
    {
        if ($this->real_type_set) {
            return [(string)$this, \implode('|', $this->real_type_set)];
        }
        return [(string)$this];
    }

    /**
     * @param array{0: string, 1?: string} $data
     * @suppress PhanAccessReadOnlyProperty this unserializes
     */
    public function __unserialize(array $data): void
    {
        if (isset($data[1])) {
            $result = UnionType::fromFullyQualifiedPHPDocAndRealString(
                $data[0],
                $data[1]
            );
            $this->type_set = $result->getTypeSet();
            $this->real_type_set = $result->getRealTypeSet();
            return;
        }
        $this->type_set = UnionType::fromFullyQualifiedPHPDocString($data[0])->getTypeSet();
    }

    /**
     * @return string
     * A human-readable string representation of this union
     * type
     */
    public function __toString(): string
    {
        // Create a new array containing the string
        // representations of each type
        $types = $this->type_set;
        $type_name_list =
            \array_map(static function (Type $type) use ($types): string {
                if (count($types) > 1 && $type instanceof IntersectionType) {
                    // Avoid ambiguity such as (Closure():A&B)
                    return '(' . $type->__toString() . ')';
                }
                return $type->__toString();
            }, $types);

        // Sort the types so that we get a stable
        // representation
        \asort($type_name_list);

        // Join them with a pipe
        return \implode('|', $type_name_list);
    }

    /**
     * @return array<string,array<int|string,string>>
     * A map from builtin function name to type information
     *
     * @see \Phan\Language\Internal\FunctionSignatureMap
     */
    public static function internalFunctionSignatureMap(int $target_php_version): array
    {
        static $php80_map = [];

        if (!$php80_map) {
            $php80_map = self::computeLatestFunctionSignatureMap();
        }
        if ($target_php_version >= 80000) {
            if ($target_php_version < 80100) {
                return $php80_map;
            }
            static $php81_map = [];
            if (!$php81_map) {
                $php81_map = self::computePHP81FunctionSignatureMap($php80_map);
            }
            if ($target_php_version < 80200) {
                return $php81_map;
            }
            static $php82_map = [];
            if (!$php82_map) {
                $php82_map = self::computePHP82FunctionSignatureMap($php81_map);
            }
            return $php82_map;
        }
        static $php74_map = [];
        if (!$php74_map) {
            $php74_map = self::computePHP74FunctionSignatureMap($php80_map);
        }
        if ($target_php_version >= 70400) {
            return $php74_map;
        }
        static $php73_map = [];
        if (!$php73_map) {
            $php73_map = self::computePHP73FunctionSignatureMap($php74_map);
        }
        if ($target_php_version >= 70300) {
            return $php73_map;
        }
        static $php72_map = [];
        if (!$php72_map) {
            $php72_map = self::computePHP72FunctionSignatureMap($php73_map);
        }
        if ($target_php_version >= 70200) {
            return $php72_map;
        }
        static $php71_map = [];
        if (!$php71_map) {
            $php71_map = self::computePHP71FunctionSignatureMap($php72_map);
        }
        if ($target_php_version >= 70100) {
            return $php71_map;
        }
        static $php70_map = [];
        if (!$php70_map) {
            $php70_map = self::computePHP70FunctionSignatureMap($php71_map);
        }
        if ($target_php_version >= 70000) {
            return $php70_map;
        }

        static $php56_map = [];
        if (!$php56_map) {
            $php56_map = self::computePHP56FunctionSignatureMap($php70_map);
        }
        return $php56_map;
    }

    /**
     * @return array<string,string[]>
     */
    private static function computeLatestFunctionSignatureMap(): array
    {
        $map = [];
        $map_raw = require(__DIR__ . '/Internal/FunctionSignatureMap.php');
        foreach ($map_raw as $key => $value) {
            $map[\strtolower($key)] = $value;
        }
        return $map;
    }

    /**
     * @return array<string,string> maps the lowercase function name to the return type
     * @internal the data format will change
     */
    public static function getLatestRealFunctionSignatureMap(int $target_php_version): array
    {
        if ($target_php_version >= 80100) {
            static $map_81;
            return $map_81 ?? ($map_81 = self::computeLatestRealFunctionSignatureMap(''));
        }
        if ($target_php_version >= 80000) {
            static $map_80;
            return $map_80 ?? ($map_80 = self::computeLatestRealFunctionSignatureMap('_php80'));
        }
        static $map_73;
        return $map_73 ?? ($map_73 = self::computeLatestRealFunctionSignatureMap('_php73'));
    }

    /**
     * @return array<string,string>
     */
    private static function computeLatestRealFunctionSignatureMap(string $version): array
    {
        $map = [];
        $map_raw = require(__DIR__ . "/Internal/FunctionSignatureMapReal{$version}.php");
        foreach ($map_raw as $key => $value) {
            $map[\strtolower($key)] = $value;
        }
        return $map;
    }

    /**
     * @param array<string,associative-array<int|string,string>> $php81_map
     * @return array<string,associative-array<int|string,string>>
     */
    private static function computePHP82FunctionSignatureMap(array $php81_map): array
    {
        $delta_raw = require(__DIR__ . '/Internal/FunctionSignatureMap_php82_delta.php');
        return self::applyDeltaToGetNewerSignatures($php81_map, $delta_raw);
    }

    /**
     * @param array<string,associative-array<int|string,string>> $php80_map
     * @return array<string,associative-array<int|string,string>>
     */
    private static function computePHP81FunctionSignatureMap(array $php80_map): array
    {
        $delta_raw = require(__DIR__ . '/Internal/FunctionSignatureMap_php81_delta.php');
        return self::applyDeltaToGetNewerSignatures($php80_map, $delta_raw);
    }

    /**
     * @param array<string,associative-array<int|string,string>> $php80_map
     * @return array<string,associative-array<int|string,string>>
     */
    private static function computePHP74FunctionSignatureMap(array $php80_map): array
    {
        $delta_raw = require(__DIR__ . '/Internal/FunctionSignatureMap_php80_delta.php');
        return self::applyDeltaToGetOlderSignatures($php80_map, $delta_raw);
    }

    /**
     * @param array<string,associative-array<int|string,string>> $php74_map
     * @return array<string,associative-array<int|string,string>>
     */
    private static function computePHP73FunctionSignatureMap(array $php74_map): array
    {
        $delta_raw = require(__DIR__ . '/Internal/FunctionSignatureMap_php74_delta.php');
        return self::applyDeltaToGetOlderSignatures($php74_map, $delta_raw);
    }

    /**
     * @param array<string,associative-array<int|string,string>> $php73_map
     * @return array<string,associative-array<int|string,string>>
     */
    private static function computePHP72FunctionSignatureMap(array $php73_map): array
    {
        $delta_raw = require(__DIR__ . '/Internal/FunctionSignatureMap_php73_delta.php');
        return self::applyDeltaToGetOlderSignatures($php73_map, $delta_raw);
    }

    /**
     * @param array<string,array<int|string,string>> $php72_map
     * @return array<string,array<int|string,string>>
     */
    private static function computePHP71FunctionSignatureMap(array $php72_map): array
    {
        $delta_raw = require(__DIR__ . '/Internal/FunctionSignatureMap_php72_delta.php');
        return self::applyDeltaToGetOlderSignatures($php72_map, $delta_raw);
    }

    /**
     * @param array<string,associative-array<int|string,string>> $php71_map
     * @return array<string,associative-array<int|string,string>>
     */
    private static function computePHP70FunctionSignatureMap(array $php71_map): array
    {
        $delta_raw = require(__DIR__ . '/Internal/FunctionSignatureMap_php71_delta.php');
        return self::applyDeltaToGetOlderSignatures($php71_map, $delta_raw);
    }

    /**
     * @param array<string,associative-array<int|string,string>> $php70_map
     * @return array<string,associative-array<int|string,string>>
     */
    private static function computePHP56FunctionSignatureMap(array $php70_map): array
    {
        $delta_raw = require(__DIR__ . '/Internal/FunctionSignatureMap_php70_delta.php');
        return self::applyDeltaToGetOlderSignatures($php70_map, $delta_raw);
    }

    /**
     * @param array<string,associative-array<int|string,string>> $older_map
     * @param array{added:array<string,associative-array<int|string,string>>,removed:array<string,associative-array<int|string,string>>,changed:array<string,array{old:associative-array<int|string,string>,new:associative-array<int|string,string>}>} $delta
     * @return array<string,associative-array<int|string,string>>
     *
     * @see applyDeltaToGetOlderSignatures - This is doing the exact same thing in reverse.
     * @suppress PhanUnreferencedPrivateMethod this will be used again when Phan supports the next PHP minor release
     */
    private static function applyDeltaToGetNewerSignatures(array $older_map, array $delta): array
    {
        foreach ($delta['removed'] as $key => $unused_signature) {
            // Would also unset alternates, but that step isn't necessary yet.
            unset($older_map[\strtolower($key)]);
        }
        foreach ($delta['added'] as $key => $signature) {
            // Would also unset alternates, but that step isn't necessary yet.
            $older_map[\strtolower($key)] = $signature;
        }
        foreach ($delta['changed'] as $key => ['new' => $signature]) {
            // Would also unset alternates, but that step isn't necessary yet.
            $older_map[\strtolower($key)] = $signature;
        }
        return $older_map;
    }

    /**
     * @param array<string,associative-array<int|string,string>> $newer_map
     * @param array{added:array<string,associative-array<int|string,string>>,removed:array<string,associative-array<int|string,string>>,changed:array<string,array{old:associative-array<int|string,string>,new:associative-array<int|string,string>}>} $delta
     * @return array<string,associative-array<int|string,string>>
     */
    private static function applyDeltaToGetOlderSignatures(array $newer_map, array $delta): array
    {
        foreach ($delta['added'] as $key => $unused_signature) {
            // Would also unset alternates, but that step isn't necessary yet.
            unset($newer_map[\strtolower($key)]);
        }
        foreach ($delta['removed'] as $key => $signature) {
            // Would also unset alternates, but that step isn't necessary yet.
            $newer_map[\strtolower($key)] = $signature;
        }
        foreach ($delta['changed'] as $key => ['old' => $signature]) {
            // Would also unset alternates, but that step isn't necessary yet.
            $newer_map[\strtolower($key)] = $signature;
        }

        // Return the newer map after modifying it to become the older map.
        return $newer_map;
    }

    /**
     * @var ?UnionType|?bool this type as the normalized version
     */
    private $as_normalized = null;

    /**
     * @return UnionType - A normalized version of this union type (May or may not be the same object, if no modifications were made)
     *
     * The following normalization rules apply
     *
     * 1. If one of the types is null or nullable, convert all types to nullable and remove "null" from the union type
     * 2. If both "true" and "false" (possibly nullable) coexist, or either coexists with "bool" (possibly nullable),
     *    then remove "true" and "false"
     *    @suppress PhanAccessReadOnlyProperty
     */
    public function asNormalizedTypes(): UnionType
    {
        if (\count($this->type_set) <= 1 && \count($this->real_type_set) <= 1) {
            // Optimization: can't simplify if there's only one type
            return $this;
        }
        $normalized = $this->as_normalized;
        if (\is_null($normalized)) {
            $this->as_normalized = $normalized = $this->asNormalizedTypesInner();
        }
        return \is_object($normalized) ? $normalized : $this;
    }

    private function asNormalizedTypesInner(): UnionType
    {
        $type_set = $this->type_set;
        $real_type_set = $this->real_type_set;
        $new_type_set = self::asNormalizedTypeSetInner($type_set);
        $new_real_type_set = self::asNormalizedTypeSetInner($real_type_set);
        if ($type_set === $new_type_set && $real_type_set === $new_real_type_set) {
            return $this;
        }
        return UnionType::of($new_type_set, $new_real_type_set);
    }

    /**
     * @param list<Type> $type_set
     * @return list<Type>
     */
    private static function asNormalizedTypeSetInner(array $type_set): array
    {
        if (\count($type_set) <= 1) {
            return $type_set;
        }
        $flags = 0;
        foreach ($type_set as $type) {
            $flags |= $type->getNormalizationFlags();
        }
        if ($flags === 0) {
            // Optimization: nothing to do if no types are null/nullable or booleans
            return $type_set;
        }
        $nullable = ($flags & Type::_bit_nullable) !== 0;
        if ($nullable) {
            $new_types = [];
            foreach ($type_set as $type) {
                if ($type instanceof NullType || $type instanceof VoidType) {
                    continue;
                }
                if ($type->isNullable()) {
                    $new_types[] = $type;
                } else {
                    $new_types[] = $type->withIsNullable(true);
                }
            }
            $builder = new UnionTypeBuilder(self::getUniqueTypes($new_types));
        } else {
            $builder = new UnionTypeBuilder($type_set);
        }

        // If this contains both true and false types, filter out both and add "bool" (or "?bool" for nullable)
        if (($flags & Type::_bit_bool_combination) === Type::_bit_bool_combination) {
            if ($nullable) {
                self::convertToTypeSetWithNormalizedNullableBools($builder);
            } else {
                self::convertToTypeSetWithNormalizedNonNullableBools($builder);
            }
        }
        $result_type_set = $builder->getTypeSet();
        if ($result_type_set === $type_set) {
            // Reuse the first array if possible, to keep memory usage down.
            return $type_set;
        }
        return $result_type_set;
    }

    /**
     * @param UnionType[] $union_types
     * @return UnionType union of these UnionTypes
     * @suppress PhanPartialTypeMismatchArgument false positive seen when no real types are known. count() would throw in php 8.0+ for non-countables.
     */
    public static function merge(array $union_types, bool $normalize_array_shapes = true): UnionType
    {
        $n = \count($union_types);
        if ($n < 2) {
            return \reset($union_types) ?: UnionType::$empty_instance;
        }
        $new_type_set = [];
        $array_shape_types = [];
        foreach ($union_types as $union_type) {
            $type_set = $union_type->type_set;
            if (\count($type_set) === 0) {
                continue;
            }
            if (\count($new_type_set) === 0) {
                // Take advantage of copy-on-write when possible (this avoids creating a new array if nothing else gets added)
                $new_type_set = $type_set;
                foreach ($type_set as $type) {
                    if ($type instanceof ArrayShapeType && $normalize_array_shapes) {
                        $array_shape_types[] = $type;
                    }
                }
                continue;
            }
            foreach ($type_set as $type) {
                $new_type_set[] = $type;
                if ($type instanceof ArrayShapeType && $normalize_array_shapes) {
                    $array_shape_types[] = $type;
                }
            }
        }
        if (\count($new_type_set) > 1) {
            $new_type_set = self::getUniqueTypes($new_type_set);
            $array_shape_types = self::getUniqueTypes($array_shape_types);
        }
        if ($array_shape_types) {
            // @phan-suppress-next-line PhanPartialTypeMismatchArgument phan can't infer new_type_set/union_types are non-empty
            $new_type_set = self::normalizeArrayShapes($new_type_set, $array_shape_types, $union_types, false);
        }
        $new_real_type_set = [];
        $array_shape_types = [];
        foreach ($union_types as $union_type) {
            $type_set = $union_type->real_type_set;
            if (\count($type_set) === 0) {
                $new_real_type_set = [];
                $array_shape_types = [];
                break;
            }
            if (\count($new_real_type_set) === 0) {
                $new_real_type_set = $type_set;
                foreach ($type_set as $type) {
                    if ($type instanceof ArrayShapeType && $normalize_array_shapes) {
                        $array_shape_types[] = $type;
                    }
                }
                continue;
            }
            foreach ($type_set as $type) {
                $new_real_type_set[] = $type;
                if ($type instanceof ArrayShapeType && $normalize_array_shapes) {
                    $array_shape_types[] = $type;
                    continue;
                }
            }
        }
        if (\count($new_real_type_set) > 1) {
            $new_real_type_set = self::getUniqueTypes($new_real_type_set);
            $array_shape_types = self::getUniqueTypes($array_shape_types);
        }
        if ($array_shape_types) {
            // @phan-suppress-next-line PhanPartialTypeMismatchArgument Phan can't count.
            $new_real_type_set = self::normalizeArrayShapes($new_real_type_set, $array_shape_types, $union_types, true);
        }
        // \Phan\Debug::debugLog("Before: " . \implode(' or ', \array_map(function (UnionType $type) : string { return $type->getDebugRepresentation(); }, $union_types)) . " array_shape_types=" . \implode(' or ', $array_shape_types) . "\n");
        return UnionType::of($new_type_set, $new_real_type_set);
        // \Phan\Debug::debugLog("After: " . $result->getDebugRepresentation() . "\n");
        // return $result;
    }

    /**
     * @param non-empty-list<Type> $type_set the elements of the type_set (both array shapes and non array shapes)
     * @param non-empty-list<ArrayShapeType> $array_shape_types the elements of the type_set that were ArrayShapeType instances
     * @param non-empty-list<UnionType> $union_types a list of two or more union types, at least one of which has array shapes
     * @param bool $from_real_type_set
     * @return non-empty-list<Type> $type_set a type set with all array shape types normalized
     */
    private static function normalizeArrayShapes(array $type_set, array $array_shape_types, array $union_types, bool $from_real_type_set): array
    {
        // If one of the union types had no array shape types, merge the array shape types of other types with the empty type
        $add_mixed = false;
        foreach ($union_types as $union_type) {
            foreach ($from_real_type_set ? $union_type->real_type_set : $union_type->type_set as $type) {
                if ($type instanceof ArrayShapeType) {
                    continue 2;
                }
            }
            $add_mixed = true;
            break;
        }
        if (!$add_mixed && \count($array_shape_types) === 1) {
            return $type_set;
        }
        // Replace the prior array shape types with the new array shape types.
        $array_shape_type = self::combineArrayShapeTypes($array_shape_types, $add_mixed);
        $new_type_set = [$array_shape_type];
        foreach ($type_set as $type) {
            if (!$type instanceof ArrayShapeType) {
                $new_type_set[] = $type;
            }
        }
        return $new_type_set;
    }

    /**
     * @param non-empty-list<ArrayShapeType> $types
     */
    private static function combineArrayShapeTypes(array $types, bool $add_mixed): ArrayShapeType
    {
        $is_nullable = false;
        $field_types_list = [];
        $common_field_types = [];
        foreach ($types as $type) {
            $is_nullable = $is_nullable || $type->isNullable();
            $field_types = $type->getFieldTypes();
            $common_field_types += $field_types;
            $field_types_list[] = $field_types;
        }
        foreach ($common_field_types as $key => $_) {
            $is_possibly_undefined = false;
            $new_element_union_types = [];
            foreach ($field_types_list as $field_types) {
                $element_union_type = $field_types[$key] ?? null;
                if (!$element_union_type) {
                    $is_possibly_undefined = true;
                    continue;
                }
                if ($add_mixed) {
                    $element_union_type = $element_union_type->eraseRealTypeSetRecursively();
                }
                $new_element_union_types[] = $element_union_type;
                $is_possibly_undefined = $is_possibly_undefined || $element_union_type->isPossiblyUndefined();
            }
            $new_element_union_type = UnionType::merge($new_element_union_types);
            if ($is_possibly_undefined) {
                $new_element_union_type = $new_element_union_type->withIsPossiblyUndefined(true);
            }
            $common_field_types[$key] = $new_element_union_type;
        }
        return ArrayShapeType::fromFieldTypes($common_field_types, $is_nullable);
    }

    /**
     * Must be called after converting nullable to non-nullable.
     * Removes false|true types and adds bool
     *
     * @param UnionTypeBuilder $builder (Containing only non-nullable values)
     */
    private static function convertToTypeSetWithNormalizedNonNullableBools(UnionTypeBuilder $builder): void
    {
        static $true_type = null;
        static $false_type = null;
        static $bool_type = null;
        if ($bool_type === null) {
            $true_type = TrueType::instance(false);
            $false_type = FalseType::instance(false);
            $bool_type = BoolType::instance(false);
        }
        if (!$builder->isEmpty()) {
            $builder->removeType($true_type);
            $builder->removeType($false_type);
        }

        $builder->addType($bool_type);
    }

    /**
     * Must be called after converting all types to null.
     * Removes ?false|?true types and adds ?bool
     *
     * @param UnionTypeBuilder $builder (Containing only non-nullable values)
     */
    private static function convertToTypeSetWithNormalizedNullableBools(UnionTypeBuilder $builder): void
    {
        static $true_type = null;
        static $false_type = null;
        static $bool_type = null;
        if ($bool_type === null) {
            $true_type = TrueType::instance(true);
            $false_type = FalseType::instance(true);
            $bool_type = BoolType::instance(true);
        }
        if (!$builder->isEmpty()) {
            $builder->removeType($true_type);
            $builder->removeType($false_type);
        }

        $builder->addType($bool_type);
    }

    /**
     * Generates a variable length string identifier that uniquely identifies the Type instances in this UnionType. (both phpdoc and real)
     * `int|string` will generate the same id as `string|int`.
     */
    public function generateUniqueId(): string
    {
        /** @var list<int> $ids */
        // Real types are given negative ids, and phpdoc types are given non-negative ids.
        $ids = [];
        foreach ($this->real_type_set as $type) {
            $ids[] = ~\spl_object_id($type);
        }
        foreach ($this->type_set as $type) {
            $ids[] = \spl_object_id($type);
        }
        // Sort the unique identifiers of Type instances so that int|string generates the same id as string|int
        \sort($ids);
        return \implode(',', $ids);
    }

    /**
     * Returns true if at least one of the types in this type set is a generic array shape
     * E.g. returns true for `array{}|false`, but not for `iterable<int,array{}>|false`
     */
    public function hasTopLevelArrayShapeTypeInstances(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type instanceof ArrayShapeType) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true if at least one of the types in this type set is not a generic array shape
     * E.g. returns true for `array{}|false`, and false for `array{}`
     */
    public function hasTopLevelNonArrayShapeTypeInstances(): bool
    {
        foreach ($this->type_set as $type) {
            if (!($type instanceof ArrayShapeType)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true if at least one of the types in this type set contains an array shape.
     * TODO: Implement for all types that can contain other types.
     * @suppress PhanUnreferencedPublicMethod
     */
    public function hasArrayShapeTypeInstances(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->hasArrayShapeTypeInstances()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true if at least one of the types in this type set contains an array shape or a literal type.
     * TODO: Implement for all types that can contain other types.
     *
     * e.g. returns true for `array<string,2>`, `2`, `array{key:int}`, etc.
     * @suppress PhanUnreferencedPublicMethod
     */
    public function hasArrayShapeOrLiteralTypeInstances(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->hasArrayShapeOrLiteralTypeInstances()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool true if at least one of the types in this type set is `mixed` or `?mixed`
     *              Returns true for `non-null-mixed` and `non-empty-mixed` as well.
     * @deprecated this function was added before `non-null-mixed`
     * @suppress PhanUnreferencedPublicMethod
     */
    public function hasMixedType(): bool
    {
        return $this->hasMixedOrNonEmptyMixedType();
    }

    /**
     * @return bool true if at least one of the types in this type set is `mixed` or `?mixed`
     *              Returns false for `non-null-mixed` and `non-empty-mixed` because those exclude some types.
     */
    public function hasMixedTypeStrict(): bool
    {
        foreach ($this->type_set as $type) {
            if (\get_class($type) === MixedType::class) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return bool true if at least one of the types in this type set is `mixed` or `?mixed`
     *              Returns true for `non-null-mixed` and `non-empty-mixed` as well.
     */
    public function hasMixedOrNonEmptyMixedType(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type instanceof MixedType) {
                return true;
            }
        }
        return false;
    }

    /**
     * Flatten literals in keys and values of top-level array shapes into non-literal types (but not standalone literals)
     * E.g. convert array{2:array{key:'value'}} to array<int,array{key:'value'}>
     */
    public function withFlattenedTopLevelArrayShapeTypeInstances(): UnionType
    {
        if (!$this->hasArrayShapeTypeInstances()) {
            return $this;
        }
        return UnionType::of(
            self::withFlattenedTopLevelArrayShapeTypeInstancesForSet($this->type_set),
            self::withFlattenedTopLevelArrayShapeTypeInstancesForSet($this->real_type_set)
        );
    }

    /**
     * Convert non-empty-array, etc. to array.
     *
     * This reflects a change that removes elements from an array.
     * @see withFlattenedTopLevelArrayShapeTypeInstances
     */
    public function withPossiblyEmptyArrays(): UnionType
    {
         return $this->asMappedUnionType(static function (Type $type): Type {
            if ($type instanceof NonEmptyArrayInterface) {
                return $type->asPossiblyEmptyArrayType();
            }
             return $type;
         });
    }

    /**
     * @param list<Type> $type_set
     * @return list<Type>
     */
    private static function withFlattenedTopLevelArrayShapeTypeInstancesForSet(array $type_set): array
    {
        $result = [];
        $has_other_array_type = false;
        $empty_array_shape_type = null;
        foreach ($type_set as $type) {
            if ($type instanceof ArrayShapeType) {
                if (\count($type->getFieldTypes()) === 0) {
                    $empty_array_shape_type = $type;
                    continue;
                }
                $has_other_array_type = true;
                foreach ($type->withFlattenedTopLevelArrayShapeTypeInstances() as $type_part) {
                    $result[] = $type_part;
                }
            } else {
                $result[] = $type;
                if ($type instanceof ArrayType) {
                    $has_other_array_type = true;
                }
            }
        }
        if ($empty_array_shape_type) {
            $is_nullable = $empty_array_shape_type->isNullable();
            if (!$has_other_array_type) {
                $result[] = ArrayType::instance($is_nullable);
            } else {
                foreach ($result as $i => $type) {
                    if ($type instanceof NonEmptyListType) {
                        $type = $type->asPossiblyEmptyArrayType();
                        $result[$i] = $type;
                    }
                    if ($is_nullable) {
                        $result[$i] = $type->withIsNullable(true);
                    }
                }
            }
        }
        // @phan-suppress-next-line PhanPartialTypeMismatchReturn phan cannot infer that the assignments do not make the result associative-array.
        return $result;
    }

    /**
     * Flatten literals in keys and values into non-literal types (but not standalone literals)
     * E.g. convert array{2:3} to array<int,string>
     */
    public function withFlattenedArrayShapeTypeInstances(): UnionType
    {
        if (!$this->hasArrayShapeTypeInstances()) {
            return $this;
        }
        return UnionType::of(
            self::withFlattenedArrayShapeTypeInstancesForSet($this->type_set),
            self::withFlattenedArrayShapeTypeInstancesForSet($this->real_type_set)
        );
    }

    /**
     * @param list<Type> $type_set
     * @return list<Type>
     */
    private static function withFlattenedArrayShapeTypeInstancesForSet(array $type_set): array
    {
        $result = [];
        $has_other_array_type = false;
        $empty_array_shape_type = null;
        foreach ($type_set as $type) {
            if ($type->hasArrayShapeTypeInstances()) {
                if ($type instanceof ArrayShapeType) {
                    if (\count($type->getFieldTypes()) === 0) {
                        $empty_array_shape_type = $type;
                        continue;
                    }
                }
                $has_other_array_type = true;
                foreach ($type->withFlattenedArrayShapeOrLiteralTypeInstances() as $type_part) {
                    $result[] = $type_part;
                }
            } else {
                $result[] = $type;
                if ($type instanceof ArrayType) {
                    $has_other_array_type = true;
                }
            }
        }
        if ($empty_array_shape_type && !$has_other_array_type) {
            $result[] = ArrayType::instance($empty_array_shape_type->isNullable());
        }
        return $result;
    }

    /**
     * Flatten literals in keys and values into non-literal types (as well as standalone literals)
     * E.g. convert array{2:3} to array<int,string>, 'somestring' to string, etc.
     */
    public function withFlattenedArrayShapeOrLiteralTypeInstances(): UnionType
    {
        if (!$this->hasArrayShapeOrLiteralTypeInstances()) {
            return $this;
        }

        return UnionType::of(
            self::withFlattenedArrayShapeOrLiteralTypeInstancesForSet($this->type_set),
            self::withFlattenedArrayShapeOrLiteralTypeInstancesForSet($this->real_type_set)
        );
    }

    /**
     * @param list<Type> $type_set
     * @return list<Type>
     */
    private static function withFlattenedArrayShapeOrLiteralTypeInstancesForSet(array $type_set): array
    {
        $result = [];
        $has_other_array_type = false;
        $empty_array_shape_type = null;
        foreach ($type_set as $type) {
            if ($type->hasArrayShapeOrLiteralTypeInstances()) {
                if ($type instanceof ArrayShapeType) {
                    if (\count($type->getFieldTypes()) === 0) {
                        $empty_array_shape_type = $type;
                        continue;
                    }
                }
                foreach ($type->withFlattenedArrayShapeOrLiteralTypeInstances() as $type_part) {
                    $result[] = $type_part;
                }
            } else {
                $result[] = $type;
            }
            if ($type instanceof ArrayType) {
                $has_other_array_type = true;
            }
        }
        if ($empty_array_shape_type && !$has_other_array_type) {
            $result[] = ArrayType::instance($empty_array_shape_type->isNullable());
        }
        return $result;
    }

    /**
     * Returns this union type after an operation that converts arrays to associative arrays is applied.
     */
    public function withAssociativeArrays(bool $can_reduce_size): UnionType
    {
        return UnionType::of(
            self::withAssociativeArraysForSet($this->type_set, $can_reduce_size),
            self::withAssociativeArraysForSet($this->real_type_set, $can_reduce_size)
        );
    }

    /**
     * @param list<Type> $type_set
     * @return list<Type> with all array types as associative arrays or array shapes
     */
    private static function withAssociativeArraysForSet(array $type_set, bool $can_reduce_size): array
    {
        foreach ($type_set as $i => $type) {
            if (!$type instanceof ArrayType) {
                continue;
            }
            if ($type instanceof ArrayShapeType) {
                continue;
            }
            $type_set[$i] = $type->asAssociativeArrayType($can_reduce_size);
        }
        return $type_set;
    }

    /**
     * Returns this union type after an operation that converts arrays with integer keys to lists is applied.
     * (e.g. array_unshift, array_merge)
     */
    public function withIntegerKeyArraysAsLists(): UnionType
    {
        return UnionType::of(
            self::withIntegerKeyArraysAsListsForSet($this->type_set),
            self::withIntegerKeyArraysAsListsForSet($this->real_type_set)
        );
    }

    /**
     * @param list<Type> $type_set
     * @return list<Type> with all array types as lists, or as arrays with mixed or string keys
     */
    private static function withIntegerKeyArraysAsListsForSet(array $type_set): array
    {
        foreach ($type_set as $i => $type) {
            if (!$type instanceof ArrayType) {
                continue;
            }
            $type_set[$i] = $type->convertIntegerKeyArrayToList();
        }
        return $type_set;
    }

    /**
     * Used to check if any type in this param type should be replaced
     * by more specific types of arguments, in non-quick mode
     */
    public function shouldBeReplacedBySpecificTypes(): bool
    {
        if ($this->isEmpty()) {
            // We don't know anything about this type, this should be replaced by more specific argument types.
            return true;
        }
        return $this->hasTypeMatchingCallback(static function (Type $type): bool {
            return $type->shouldBeReplacedBySpecificTypes();
        });
    }

    /**
     * Removes $field_key from the fields of top-level array shapes of this union type.
     *
     * @param int|string|float|bool $field_key
     */
    public function withoutArrayShapeField($field_key): UnionType
    {
        return $this->asMappedUnionType(static function (Type $type) use ($field_key): Type {
            if ($type instanceof ArrayShapeType) {
                return $type->withoutField($field_key);
            }
            return $type;
        });
    }

    /**
     * Mark this union type as being possibly undefined.
     * This is used for union types of variables and for values of array shapes.
     *
     * @suppress PhanAccessReadOnlyProperty this is the only way to set is_possibly_undefined
     */
    public function withIsPossiblyUndefined(bool $is_possibly_undefined): UnionType
    {
        if (!$is_possibly_undefined) {
            return $this;
        }
        // TODO: Would using [NullType] make more sense here?
        $result = new AnnotatedUnionType($this->type_set, true, $this->real_type_set);
        $result->is_possibly_undefined = $is_possibly_undefined;
        return $result;
    }

    /**
     * Mark this union type as being possibly definitely undefined.
     * This is used for properties (of $this) and is planned for local variables.
     *
     * Base implementation. Overridden by AnnotatedUnionType.
     * @suppress PhanAccessReadOnlyProperty this is the only way to set is_possibly_undefined
     */
    public function withIsDefinitelyUndefined(): UnionType
    {
        $result = new AnnotatedUnionType($this->type_set, true, $this->real_type_set);
        $result->is_possibly_undefined = AnnotatedUnionType::DEFINITELY_UNDEFINED;
        return $result;
    }

    /**
     * Base implementation. Overridden by AnnotatedUnionType.
     * Used for fields of array shapes.
     *
     * This is distinct from null - The array shape offset potentially doesn't exist at all, which is different from existing and being null.
     */
    public function isPossiblyUndefined(): bool
    {
        return false;
    }

    /**
     * Base implementation. Overridden by AnnotatedUnionType.
     *
     * Used for properties(tracking unset of $this) and planned for variables.
     */
    public function isDefinitelyUndefined(): bool
    {
        return false;
    }

    /**
     * Returns true if at least one of the types in this union type is a class-like defining the method __toString()
     *
     * Callers should convert union types to the expanded union types first.
     * TODO: is that necessary?
     */
    public function hasClassWithToStringMethod(CodeBase $code_base, Context $context): bool
    {
        try {
            foreach ($this->asClassList($code_base, $context) as $clazz) {
                // NOTE: It's possible for an internal class to cast to string without implementing __toString.
                // (PHP 8 implements that in more places)
                // The $is_direct to hasMethodWithName currently doesn't matter one way or another for that.
                if ($clazz->hasMethodWithName($code_base, "__toString", false)) {
                    return true;
                }
            }
        } catch (CodeBaseException $_) {
            // Swallow "Cannot find class", go on to emit issue
        }
        return false;
    }

    /**
     * Returns true if the union type is exclusively generators
     */
    public function isExclusivelyGenerators(): bool
    {
        foreach ($this->type_set as $type) {
            if (!$type->isGenerator()) {
                return false;
            }
        }
        return \count($this->type_set) > 0;
    }

    /**
     * Gets the type of this converted to a generator.
     * E.g. converts the type of an iterable/array/Generator `$x`
     * to the type of a generator with the implementation `yield from $x`
     */
    public function asGeneratorTemplateType(): Type
    {
        $fallback_values = UnionType::empty();
        $fallback_keys = UnionType::empty();

        foreach ($this->type_set as $type) {
            if ($type->isGenerator()) {
                if ($type->hasTemplateParameterTypes()) {
                    return $type;
                }
            }
            // TODO: support Iterator<T> or Traversable<T> or iterable<T>
            if ($type instanceof GenericArrayType) {
                $fallback_values = $fallback_values->withType($type->genericArrayElementType());
                $key_type = $type->getKeyType();
                if ($key_type === GenericArrayType::KEY_INT) {
                    $fallback_keys = $fallback_keys->withType(IntType::instance(false));
                } elseif ($key_type === GenericArrayType::KEY_STRING) {
                    $fallback_keys = $fallback_keys->withType(StringType::instance(false));
                }
            } elseif ($type instanceof ArrayShapeType && $type->isNotEmptyArrayShape()) {
                $fallback_values = $fallback_values->withUnionType($type->genericArrayElementUnionType());
                $fallback_keys = $fallback_keys->withUnionType(GenericArrayType::unionTypeForKeyType($type->getKeyType()));
            }
        }

        // @phan-suppress-next-line PhanThrowTypeAbsentForCall
        $result = Type::fromFullyQualifiedString('\Generator');
        if ($fallback_keys->typeCount() > 0 || $fallback_values->typeCount() > 0) {
            $template_types = $fallback_keys->typeCount() > 0 ? [$fallback_keys, $fallback_values] : [$fallback_values];
            $result = $result->fromType($result, $template_types);
        }
        return $result;
    }

    /**
     * @return Generator<Type,Type> ($outer_type => $inner_type)
     *
     * This includes classes, StaticType (and "self"), and TemplateType.
     * This includes duplicate definitions
     * TODO: Warn about Closure Declarations with invalid parameters...
     *
     * TODO: Use different helper for GoToDefinitionRequest
     */
    public function getReferencedClasses(): Generator
    {
        foreach ($this->withFlattenedArrayShapeOrLiteralTypeInstances()->getTypeSet() as $outer_type) {
            foreach ($outer_type->getReferencedClasses() as $type) {
                yield $outer_type => $type;
            }
        }
    }

    /**
     * @return UnionType the union type of applying the minus operator to an expression with this union type
     */
    public function applyUnaryMinusOperator(): UnionType
    {
        /** @param int|float $value */
        return $this->applyNumericOperation(static function ($value): ScalarType {
            $result = -$value;
            if (\is_int($result)) {
                return LiteralIntType::instanceForValue($result, false);
            }
            // -INT_MIN is a float.
            return LiteralFloatType::instanceForValue($result, false);
        });
    }

    /**
     * @return UnionType the union type of applying the unary bitwise not operator to an expression with this union type
     */
    public function applyUnaryBitwiseNotOperator(): UnionType
    {
        return UnionType::of(
            self::applyUnaryBitwiseNotOperatorToList($this->type_set, false),
            self::applyUnaryBitwiseNotOperatorToList($this->real_type_set, true)
        );
    }

    /**
     * @param list<Type> $type_set
     * @return list<IntType|StringType>
     */
    private static function applyUnaryBitwiseNotOperatorToList(array $type_set, bool $is_real): array
    {
        if (!$type_set) {
            // Can be int|string
            // TODO: Add a plugin to infer the result of typeSetFromString on union types of literals
            // @phan-suppress-next-line PhanTypeMismatchReturn
            return UnionType::typeSetFromString($is_real ? 'int|string' : 'int');
        }
        $result = [];
        foreach ($type_set as $type) {
            // ~null is an error, don't check isNullable()
            if ($type instanceof LiteralTypeInterface) {
                try {
                    $value = $type->getValue();
                    if (\is_float($value)) {
                        $value = (int)$value;
                    }
                    // throws error for bool/null
                    $value = ~$value;
                } catch (\Error $_) {
                    continue;
                }

                if (is_int($value)) {
                    $result[] = LiteralIntType::instanceForValue($value, false);
                } else {
                    $result[] = LiteralStringType::instanceForValue($value, false);
                }
                continue;
            }
            if ($type instanceof IntType || $type instanceof FloatType) {
                $result[] = IntType::instance(false);
            } elseif ($type instanceof StringType || $type instanceof CallableType) {
                $result[] = StringType::instance(false);
            } elseif ($type instanceof MixedType) {
                $result[] = StringType::instance(false);
                $result[] = IntType::instance(false);
            }
            // Not going to bother with objects for now
        }
        return $result ?: self::intOrStringTypeSet();
    }

    /**
     * Returns the boolean negation of this type.
     */
    public function applyUnaryNotOperator(): UnionType
    {
        return UnionType::of(
            self::applyUnaryNotOperatorToList($this->type_set),
            self::applyUnaryNotOperatorToList($this->real_type_set)
        );
    }

    /**
     * @param Type[] $type_set
     * @return list<Type>
     */
    private static function applyUnaryNotOperatorToList(array $type_set): array
    {
        $contains_falsey = false;
        $contains_truthy = false;
        foreach ($type_set as $type) {
            if ($type->isPossiblyFalsey()) {
                $contains_falsey = true;
            }
            if ($type->isPossiblyTruthy()) {
                $contains_truthy = true;
            }
            if ($contains_falsey && $contains_truthy) {
                return UnionType::typeSetFromString('bool');
            }
        }
        if ($contains_truthy) {
            return UnionType::typeSetFromString('false');
        } elseif ($contains_falsey) {
            return UnionType::typeSetFromString('true');
        }
        return UnionType::typeSetFromString('bool');
    }

    /**
     * Returns the boolean cast of this type
     */
    public function applyBoolCast(): UnionType
    {
        return UnionType::of(
            self::applyBoolCastToList($this->type_set),
            self::applyBoolCastToList($this->real_type_set)
        );
    }

    /**
     * @param Type[] $type_set
     * @return list<Type>
     */
    private static function applyBoolCastToList(array $type_set): array
    {
        $contains_falsey = false;
        $contains_truthy = false;
        foreach ($type_set as $type) {
            if ($type->isPossiblyFalsey()) {
                $contains_falsey = true;
            }
            if ($type->isPossiblyTruthy()) {
                $contains_truthy = true;
            }
            if ($contains_falsey && $contains_truthy) {
                return UnionType::typeSetFromString('bool');
            }
        }
        if ($contains_truthy) {
            return UnionType::typeSetFromString('true');
        } elseif ($contains_falsey) {
            return UnionType::typeSetFromString('false');
        }
        return UnionType::typeSetFromString('bool');
    }

    /**
     * @return list<IntType|StringType>
     * @suppress PhanPartialTypeMismatchReturn not able to infer subclass from typeSetFromString
     */
    private static function intOrStringTypeSet(): array
    {
        static $types;
        return $types ?? ($types = UnionType::typeSetFromString('int|string'));
    }

    /**
     * @return UnionType the union type of applying the unary plus operator to an expression with this union type
     */
    public function applyUnaryPlusOperator(): UnionType
    {
        /** @param int|float $value */
        return $this->applyNumericOperation(static function ($value): ScalarType {
            $result = +$value;
            if (\is_int($result)) {
                return LiteralIntType::instanceForValue($result, false);
            }
            return LiteralFloatType::instanceForValue($result, false);
        });
    }

    /**
     * @param Closure(int|float): ScalarType $operation
     */
    private function applyNumericOperation(Closure $operation): UnionType
    {
        return UnionType::of(
            self::applyNumericOperationToList($this->type_set, $operation),
            self::applyNumericOperationToList($this->real_type_set, $operation)
        );
    }

    /**
     * @param List<Type> $type_set
     * @param Closure(int|float): ScalarType $operation
     * @return list<ScalarType>
     */
    private static function applyNumericOperationToList(array $type_set, Closure $operation): array
    {
        $added_fallbacks = false;
        $result = [];
        foreach ($type_set as $type) {
            if ($type->isNullable()) {
                $result[] = LiteralIntType::instanceForValue(0, false);
            }
            if ($type instanceof LiteralIntType || $type instanceof LiteralFloatType) {
                $result[] = $operation($type->getValue());
            } else {
                if ($type instanceof LiteralStringType) {
                    if (\is_numeric($type->getValue())) {
                        $result[] = $operation(+$type->getValue());
                    } else {
                        // TODO: Could warn about non-numeric operand instead
                        $result[] = LiteralIntType::instanceForValue(0, false);
                    }
                    continue;
                }
                if ($added_fallbacks) {
                    continue;
                }
                if (!($type instanceof IntType)) {
                    $result[] = FloatType::instance(false);
                    if (!($type instanceof FloatType)) {
                        $result[] = IntType::instance(false);
                    }
                    $added_fallbacks = true;
                } else {
                    $result[] = IntType::instance(false);
                    // Keep added_fallbacks false in case this needs to add FloatType
                }
            }
        }
        if (!$result) {
            // @phan-suppress-next-line PhanTypeMismatchReturn
            return UnionType::typeSetFromString('int|float');
        }
        return $result;
    }

    /**
     * @return ?string|?float|?int|bool|null
     * If this union type can be represented by a single scalar value,
     * then this returns that scalar value.
     *
     * Otherwise, this returns null.
     */
    public function asSingleScalarValueOrNull()
    {
        $type_set = $this->type_set;
        if (\count($type_set) !== 1) {
            return null;
        }
        $type = \reset($type_set);
        switch (\get_class($type)) {
            case LiteralIntType::class:
                return $type->isNullable() ? null : $type->getValue();
            case LiteralFloatType::class:
                return $type->isNullable() ? null : $type->getValue();
            case LiteralStringType::class:
                return $type->isNullable() ? null : $type->getValue();
            case FalseType::class:
                return false;
            case TrueType::class:
                return true;
            // case NullType::class:
            // case VoidType::class:
            default:
                return null;
        }
    }

    /**
     * @return ?string|?float|?int|bool|null|?UnionType
     * If this union type can be represented by a single scalar value or null,
     * then this returns that scalar value.
     *
     * Otherwise, this returns $this.
     */
    public function asSingleScalarValueOrNullOrSelf()
    {
        $type_set = $this->type_set;
        if (\count($type_set) !== 1) {
            return $this;
        }
        $type = \reset($type_set);
        if ($type->isNullable()) {
            return ($type instanceof NullType || $type instanceof VoidType) ? null : $this;
        }
        switch (\get_class($type)) {
            case LiteralIntType::class:
            case LiteralStringType::class:
                return $type->getValue();
            case FalseType::class:
                return false;
            case TrueType::class:
                return true;
            default:
                return $this;
        }
    }

    /**
     * If this union type can be represented by a single scalar value or null,
     * then this returns true.
     */
    public function isSingleScalarValue(): bool
    {
        $type_set = $this->type_set;
        if (\count($type_set) !== 1) {
            return false;
        }
        $type = \reset($type_set);
        if ($type->isNullable()) {
            return $type instanceof NullType || $type instanceof VoidType;
        }
        return $type instanceof LiteralTypeInterface;
    }

    /**
     * @return ?array|?string|?float|?int|bool|null|?UnionType
     * If this union type can be represented by a PHP value or null,
     * then this returns that PHP value.
     *
     * Otherwise, this returns $this.
     */
    public function asValueOrNullOrSelf()
    {
        $type_set = $this->type_set;
        if (\count($type_set) !== 1) {
            return $this;
        }
        $type = \reset($type_set);
        // @phan-suppress-next-line PhanPossiblyNonClassMethodCall
        if ($type->isNullable()) {
            return ($type instanceof NullType || $type instanceof VoidType) ? null : $this;
        }
        // @phan-suppress-next-line PhanPossiblyFalseTypeArgumentInternal
        switch (\get_class($type)) {
            case ArrayShapeType::class:
                return $type->asArrayLiteralOrNull() ?? $this;
            case LiteralIntType::class:
                return $type->getValue();
            case LiteralStringType::class:
                return $type->getValue();
            case FalseType::class:
                return false;
            case TrueType::class:
                return true;
            case NullType::class:
                return null;
            default:
                return $this;
        }
    }

    /**
     * @return list<string>
     * Returns a list of known strings for LiteralStringType instances in this union type.
     * E.g. for `?'foo'|?'bar'|?false|?T`, returns `['foo', 'bar']`
     * @suppress PhanUnreferencedPublicMethod provided for plugins
     */
    public function asStringScalarValues(): array
    {
        $result = [];
        foreach ($this->type_set as $type) {
            if ($type instanceof LiteralStringType) {
                $result[] = $type->getValue();
            }
        }
        return $result;
    }

    /**
     * @return list<int>
     * Returns a list of known integers for LiteralIntType instances in this union type.
     * E.g. for `?11|?2|?false|?T`, returns `[11, 2]`
     * @suppress PhanUnreferencedPublicMethod provided for plugins
     */
    public function asIntScalarValues(): array
    {
        $result = [];
        foreach ($this->type_set as $type) {
            if ($type instanceof LiteralIntType) {
                $result[] = $type->getValue();
            }
        }
        return $result;
    }

    /**
     * @return ?list<?int|?bool|?string|?float>
     * Returns a list of known scalars that this union type could be.
     * E.g. for `?11|?2|?false|?'str'|?T`, returns `[11, 2, false, 'str', null]`
     *
     * If $strict is true, this will return null if there are non-scalar types.
     * @suppress PhanUnreferencedPublicMethod provided for plugins
     */
    public function asScalarValues(bool $strict = false): ?array
    {
        $result = [];
        $has_null = false;
        $has_false = false;
        $has_true = false;
        foreach ($this->type_set as $type) {
            if ($type->isNullable()) {
                $has_null = true;
            }
            switch (\get_class($type)) {
                case LiteralIntType::class:
                case LiteralFloatType::class:
                case LiteralStringType::class:
                    $result[] = $type->getValue();
                    break;
                case FalseType::class:
                    $has_false = true;
                    break;
                case TrueType::class:
                    $has_true = true;
                    break;
                case BoolType::class:
                    $has_true = true;
                    $has_false = true;
                    break;
                case NullType::class:
                case VoidType::class:
                    // already set $has_null = true;
                    break;
                default:
                    if ($strict) {
                        return null;
                    }
                    break;
            }
        }
        if ($has_null) {
            $result[] = null;
        }
        if ($has_false) {
            $result[] = false;
        }
        if ($has_true) {
            $result[] = true;
        }
        return $result;
    }

    /**
     * Returns true if this contains a type that is definitely nullable or a non-object.
     * e.g. returns true for ?T, T|false, T|array
     *      returns false for T|callable, object, T|iterable, etc.
     */
    public function containsDefiniteNonObjectType(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isNullableLabeled() || $type->isDefiniteNonObjectType()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true if this contains a type that is definitely nullable or a non-object/non-string.
     * e.g. returns true for ?T, T|false, T|array
     *      returns false for T|callable, object, T|iterable, etc.
     */
    public function containsDefiniteNonObjectAndNonClassType(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isNullableLabeled() || ($type->isDefiniteNonObjectType() && !$type instanceof StringType)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true if at least one type in this union type definitely can't be cast to `callable`
     */
    public function containsDefiniteNonCallableType(CodeBase $code_base): bool
    {
        foreach ($this->type_set as $type) {
            if ($type->isNullableLabeled() || $type->isDefiniteNonCallableType($code_base)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true if either (1) this is the empty type, or (2) at least one type in this union type can't be ruled out as being callable.
     */
    public function hasPossiblyCallableType(CodeBase $code_base): bool
    {
        foreach ($this->type_set as $type) {
            if (!$type->isDefiniteNonCallableType($code_base)) {
                return true;
            }
        }
        return \count($this->type_set) === 0;
    }

    /**
     * Returns the union type resulting from applying the `++`/`--` operator to an expression with union type.
     * TODO: Compute the real type set
     */
    public function getTypeAfterIncOrDec(): UnionType
    {
        $result = UnionType::empty();
        foreach ($this->type_set as $type) {
            $result = $result->withUnionType($type->getTypeAfterIncOrDec());
        }
        return $result;
    }

    /**
     * @param TemplateType $template_type the template type that this union type is being searched for
     *
     * @return ?Closure(UnionType, Context):UnionType a closure to map types to the template type wherever it was in the original union type
     */
    public function getTemplateTypeExtractorClosure(CodeBase $code_base, TemplateType $template_type): ?Closure
    {
        $closure = null;
        foreach ($this->type_set as $type) {
            $closure = TemplateType::combineParameterClosures(
                $closure,
                $type->getTemplateTypeExtractorClosure($code_base, $template_type)
            );
        }
        return $closure;
    }

    /**
     * Returns true if this references $template_type in any way
     */
    public function usesTemplateType(TemplateType $template_type): bool
    {
        $new_union_type = $this->withTemplateParameterTypeMap([
            $template_type->getName() => UnionType::fromFullyQualifiedPHPDocString('mixed'),
        ]);
        return !$this->isEqualTo($new_union_type);
    }

    /**
     * @return bool
     * True if this is the void type
     */
    public function isVoidType(): bool
    {
        $type_set = $this->type_set;
        if (\count($type_set) !== 1) {
            return false;
        }
        return \reset($type_set) instanceof VoidType;
    }

    /**
     * @return bool
     * True if this is the never type
     */
    public function isNeverType(): bool
    {
        $type_set = $this->type_set;
        if (\count($type_set) !== 1) {
            return false;
        }
        return \reset($type_set) instanceof NeverType;
    }

    /**
     * Shorter version of `UnionType::of($this->getTypeSet(), [$type])`
     * @suppress PhanAccessReadOnlyProperty
     */
    public function withRealType(Type $type): UnionType
    {
        $real_type_set = [$type];
        if ($this->real_type_set === $real_type_set) {
            return $this;
        }
        if (!$this->type_set) {
            return $type->asRealUnionType();
        }
        $new_type = clone($this);
        $new_type->real_type_set = $real_type_set;
        return $new_type;
    }

    /**
     * Shorter version of `UnionType::of($this->type_set, $real_type_set)`
     * @param ?list<Type> $real_type_set
     * @suppress PhanAccessReadOnlyProperty
     */
    public function withRealTypeSet(?array $real_type_set): UnionType
    {
        if ($this->real_type_set === $real_type_set) {
            return $this;
        }
        if (!$real_type_set) {
            return $this->eraseRealTypeSetRecursively();
        }
        if (!$this->type_set) {
            return UnionType::of($real_type_set, $real_type_set);
        }
        $new_type = clone($this);
        $new_type->real_type_set = $real_type_set;
        return $new_type;
    }

    /**
     * Converts the real part of the union type to a standalone union type
     */
    public function getRealUnionType(): UnionType
    {
        $real_type_set = $this->real_type_set;
        if ($this->type_set === $real_type_set) {
            return $this;
        }
        if (!$real_type_set) {
            return UnionType::empty();
        } elseif (\count($real_type_set) === 1) {
            return \reset($real_type_set)->asRealUnionType();
        }
        return new UnionType($real_type_set, true, $real_type_set);
    }

    /**
     * Converts a phpdoc type into the real union type equivalent.
     * @see UnionType::getRealUnionType() instead to create a union type from the real type set.
     */
    public function asRealUnionType(): UnionType
    {
        return UnionType::ofUnique($this->type_set, $this->type_set);
    }

    /**
     * Check if this is exclusively real types.
     * TODO: Could check if real types are in a different order
     */
    public function isExclusivelyRealTypes(): bool
    {
        return \count($this->real_type_set) > 0 && $this->type_set === $this->real_type_set;
    }

    /**
     * Returns a detailed representation of this union type that can be used to debug issues when developing.
     * The representation may change - this should not be used for issue messages, etc.
     *
     * @suppress PhanUnreferencedPublicMethod
     */
    public function getDebugRepresentation(): string
    {
        $representation = $this->__toString();
        if ($this->real_type_set) {
            $representation .= "(real=" . $this->getRealUnionType()->__toString() . ")";
        }
        if ($representation === '') {
            return '(empty union type)';
        }
        return $representation;
    }

    /**
     * Returns true if this type has types for which `+expr` isn't an integer.
     */
    public function hasTypesCoercingToNonInt(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type instanceof FloatType || $type instanceof StringType) {
                // TODO Could check for LiteralStringType
                return true;
            }
        }
        return \count($this->type_set) === 0;
    }

    /**
     * Returns true if this is the empty array shape (or the nullable version of it)
     */
    public function isEmptyArrayShape(): bool
    {
        foreach ($this->type_set as $type) {
            if (!($type instanceof ArrayShapeType) || $type->isNotEmptyArrayShape()) {
                return false;
            }
        }
        return \count($this->type_set) !== 0;
    }

    /**
     * Overridden in AnnotatedUnionType, which can be possibly nullable
     * @suppress PhanUnreferencedPublicMethod possibly useful for future code.
     */
    public function convertUndefinedToNullable(): UnionType
    {
        return $this;
    }

    /**
     * Returns a generator that yields all types and subtypes in the phpdoc type set.
     *
     * For example, for the union type `MyClass[]|false`, 3 types will be generated: `MyClass[]`, `MyClass`, and `false`.
     * This does not deduplicate types.
     *
     * @return Generator<Type>
     */
    public function getTypesRecursively(): Generator
    {
        foreach ($this->type_set as $type) {
            yield from $type->getTypesRecursively();
        }
    }

    /**
     * Emit an issue and return true if this intersection type contains an impossible combination
     */
    public function checkImpossibleCombination(CodeBase $code_base, Context $context): bool
    {
        $result = false;
        foreach ($this->type_set as $type) {
            if ($type->checkImpossibleCombination($code_base, $context)) {
                $result = true;
            }
        }
        return $result;
    }

    /**
     * Check if this union type contains any intersection types
     * @suppress PhanUnreferencedPublicMethod
     */
    public function hasIntersectionTypes(): bool
    {
        foreach ($this->type_set as $type) {
            if ($type instanceof IntersectionType) {
                return true;
            }
        }
        return false;
    }
}

UnionType::init();
