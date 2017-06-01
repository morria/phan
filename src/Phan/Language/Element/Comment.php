<?php
declare(strict_types=1);
namespace Phan\Language\Element;

use Phan\CodeBase;
use Phan\Config;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Language\Element\Comment\Parameter as CommentParameter;
use Phan\Language\Element\Comment\Method as CommentMethod;
use Phan\Language\Element\Flags;
use Phan\Language\Type;
use Phan\Language\Type\TemplateType;
use Phan\Language\Type\VoidType;
use Phan\Language\UnionType;
use Phan\Library\None;
use Phan\Library\Option;
use Phan\Library\Some;

/**
 * Handles extracting information(param types, return types, magic methods/properties, etc.) from phpdoc comments.
 * Instances of Comment contain the extracted information.
 */
class Comment
{
    const ON_CLASS      = 1;
    const ON_VAR        = 2;
    const ON_PROPERTY   = 3;
    const ON_CONST      = 4;
    // TODO: Handle closure.
    const ON_METHOD     = 5;
    const ON_FUNCTION   = 6;

    // List of types that are method-like
    const FUNCTION_LIKE = [
        self::ON_METHOD,
        self::ON_FUNCTION,
    ];

    const HAS_VAR_ANNOTATION = [
        self::ON_METHOD,
        self::ON_FUNCTION,
        self::ON_VAR,
        self::ON_PROPERTY,
        self::ON_CLASS,
        self::ON_CONST,
    ];

    const VAR_LIKE = [
        self::ON_VAR,
        self::ON_PROPERTY,
        self::ON_CLASS
    ];

    const NAME_FOR_TYPE = [
        self::ON_CLASS      => 'class',
        self::ON_VAR        => 'variable',
        self::ON_PROPERTY   => 'property',
        self::ON_CONST      => 'constant',
        self::ON_METHOD     => 'method',
        self::ON_FUNCTION   => 'function',
    ];

    const word_regex = '([a-zA-Z_\x7f-\xff\\\][a-zA-Z0-9_\x7f-\xff\\\]*)';

    /**
     * @var int - contains a subset of flags to set on elements
     * Flags::CLASS_FORBID_UNDECLARED_MAGIC_PROPERTIES
     * Flags::CLASS_FORBID_UNDECLARED_MAGIC_METHODS
     * Flags::IS_DEPRECATED
     */
    private $comment_flags = 0;

    /**
     * @var CommentParameter[]
     * A list of CommentParameters from var declarations
     */
    private $variable_list = [];

    /**
     * @var CommentParameter[]
     * A list of CommentParameters from param declarations
     */
    private $parameter_list = [];

    /**
     * @var CommentParameter[]
     * A map from variable name to CommentParameters from
     * param declarations
     */
    private $parameter_map = [];

    /**
     * @var string[]
     * A list of template types parameterizing a generic class
     */
    private $template_type_list = [];

    /**
     * @var Option<Type>|null
     * Classes may specify their inherited type explicitly
     * via `(at)inherits Type`.
     */
    private $inherited_type = null;

    /**
     * @var UnionType|null
     * A UnionType defined by an (at)return directive
     */
    private $return_union_type = null;

    /**
     * @var string[]
     * A list of issue types to be suppressed
     */
    private $suppress_issue_list = [];

    /**
     * @var CommentParameter[]
     * A mapping from magic property parameters to types.
     */
    private $magic_property_map = [];

    /**
     * @var CommentMethod[]
     * A mapping from magic methods to parsed parameters, name, and return types.
     */
    private $magic_method_map = [];

    /**
     * @var Option<Type>
     * An optional class name defined by an (at)PhanClosureScope directive.
     * (overrides the class in which it is analyzed)
     */
    private $closure_scope;

    /**
     * A private constructor meant to ingest a parsed comment
     * docblock.
     *
     * @param int $comment_flags uses the following flags
     * - Flags::IS_DEPRECATED
     *   Set to true if the comment contains a 'deprecated'
     *   directive.
     * - Flags::CLASS_FORBID_UNDECLARED_MAGIC_PROPERTIES
     * - Flags::CLASS_FORBID_UNDECLARED_MAGIC_METHODS
     *
     * @param CommentParameter[] $variable_list
     *
     * @param CommentParameter[] $parameter_list
     *
     * @param string[] $template_type_list
     * A list of template types parameterizing a generic class
     *
     * @param Option<Type> $inherited_type
     * An override on the type of the extended class
     *
     * @param UnionType $return_union_type
     *
     * @param string[] $suppress_issue_list
     * A list of tags for error type to be suppressed
     *
     * @param CommentParameter[] $magic_property_list
     *
     * @param CommentMethod[] $magic_method_list
     *
     * @param Option<Type> $closure_scope
     * For closures: Allows us to document the class of the object
     * to which a closure will be bound.
     */
    private function __construct(
        int $comment_flags,
        array $variable_list,
        array $parameter_list,
        array $template_type_list,
        Option $inherited_type,
        UnionType $return_union_type,
        array $suppress_issue_list,
        array $magic_property_list,
        array $magic_method_list,
        Option $closure_scope
    ) {
        $this->comment_flags = $comment_flags;
        $this->variable_list = $variable_list;
        $this->parameter_list = $parameter_list;
        $this->template_type_list = $template_type_list;
        $this->inherited_type = $inherited_type;
        $this->return_union_type = $return_union_type;
        $this->suppress_issue_list = $suppress_issue_list;
        $this->closure_scope = $closure_scope;

        foreach ($this->parameter_list as $i => $parameter) {
            $name = $parameter->getName();
            if (!empty($name)) {
                // Add it to the named map
                $this->parameter_map[$name] = $parameter;

                // Remove it from the offset map
                unset($this->parameter_list[$i]);
            }
        }
        foreach ($magic_property_list as $property) {
            $name = $property->getName();
            if (!empty($name)) {
                // Add it to the named map
                // TODO: Detect duplicates, emit warning for duplicates.
                // TODO(optional): Emit Issues when a property with only property-read is written to
                // or vice versa.
                $this->magic_property_map[$name] = $property;
            }
        }
        foreach ($magic_method_list as $method) {
            $name = $method->getName();
            if (!empty($name)) {
                // Add it to the named map
                // TODO: Detect duplicates, emit warning for duplicates.
                $this->magic_method_map[$name] = $method;
            }
        }
    }

    /**
     * @param string $comment full text of doc comment
     * @param CodeBase $code_base
     * @param Context $context
     * @param int $comment_type self::ON_* (the type of comment this is)
     * @return Comment
     * A comment built by parsing the given doc block
     * string.
     */
    public static function fromStringInContext(
        string $comment,
        CodeBase $code_base,
        Context $context,
        int $lineno,
        int $comment_type
    ) : Comment {

        if (!Config::get()->read_type_annotations) {
            return new Comment(
                0, [], [], [], new None, new UnionType(), [], [], [], new None
            );
        }

        $variable_list = [];
        $parameter_list = [];
        $template_type_list = [];
        $inherited_type = new None;
        $return_union_type = new UnionType();
        $suppress_issue_list = [];
        $magic_property_list = [];
        $magic_method_list = [];
        $closure_scope = new None;
        $comment_flags = 0;

        $lines = explode("\n", $comment);

        /**
         * @param int[] $validTypes
         * @return void
         */
        $check_compatible = function(string $paramName, array $validTypes) use($code_base, $context, $comment_type, $lineno) {
            if (!in_array($comment_type, $validTypes, true)) {
                self::emitInvalidCommentForDeclarationType(
                    $code_base,
                    $context,
                    $paramName,
                    $comment_type,
                    $lineno
                );
            }
        };

        foreach ($lines as $line) {

            if (stripos($line, '@param') !== false) {
                if (preg_match('/@param\b/i', $line)) {
                    $check_compatible('@param', Comment::FUNCTION_LIKE);
                    $parameter_list[] =
                        self::parameterFromCommentLine($code_base, $context, $line, false, $lineno);
                }
            } elseif (stripos($line, '@var') !== false && preg_match('/@var\b/i', $line)) {
                $check_compatible('@var', Comment::HAS_VAR_ANNOTATION);
                $variable_list[] =
                    self::parameterFromCommentLine($code_base, $context, $line, true, $lineno);
            } elseif (stripos($line, '@template') !== false) {

                // Make sure support for generic types is enabled
                if (Config::get()->generic_types_enabled) {
                    $check_compatible('@template', [Comment::ON_CLASS]);
                    if (($template_type =
                        self::templateTypeFromCommentLine($context, $line))
                    ) {
                        $template_type_list[] = $template_type;
                    }
                }
            } elseif (stripos($line, '@inherits') !== false) {
                $check_compatible('@inherits', [Comment::ON_CLASS]);
                // Make sure support for generic types is enabled
                if (Config::get()->generic_types_enabled) {
                    $inherited_type =
                        self::inheritsFromCommentLine($context, $line);
                }
            } elseif (stripos($line, '@return') !== false) {
                if (preg_match('/@return\b/i', $line)) {
                    $check_compatible('@return', Comment::FUNCTION_LIKE);
                    $return_union_type =
                        self::returnTypeFromCommentLine($code_base, $context, $line, $lineno);
                } else if (stripos($line, '@returns') !== false) {
                    Issue::maybeEmit(
                        $code_base,
                        $context,
                        Issue::MisspelledAnnotation,
                        $lineno,
                        '@returns',
                        '@return'
                    );
                }
            } elseif (stripos($line, '@suppress') !== false) {
                $suppress_issue_list[] =
                    self::suppressIssueFromCommentLine($line);
            } elseif (strpos($line, '@property') !== false) {
                $check_compatible('@property', [Comment::ON_CLASS]);
                // Make sure support for magic properties is enabled.
                if (Config::get()->read_magic_property_annotations) {
                    $magic_property = self::magicPropertyFromCommentLine($code_base, $context, $line, $lineno);
                    if ($magic_property !== null) {
                        $magic_property_list[] = $magic_property;
                    }
                }
            } elseif (strpos($line, '@method') !== false) {
                // Make sure support for magic methods is enabled.
                if (Config::get()->read_magic_method_annotations) {
                    $check_compatible('@method', [Comment::ON_CLASS]);
                    $magic_method = self::magicMethodFromCommentLine($code_base, $context, $line, $lineno);
                    if ($magic_method !== null) {
                        $magic_method_list[] = $magic_method;
                    }
                }
            } elseif (stripos($line, '@PhanClosureScope') !== false) {
                // TODO: different type for closures
                $check_compatible('@PhanClosureScope', Comment::FUNCTION_LIKE);
                $closure_scope = self::getPhanClosureScopeFromCommentLine($context, $line);
            } elseif (stripos($line, '@phan-forbid-undeclared-magic-properties') !== false) {
                $check_compatible('@phan-forbid-undeclared-magic-properties', [Comment::ON_CLASS]);
                $comment_flags |= Flags::CLASS_FORBID_UNDECLARED_MAGIC_PROPERTIES;
            } elseif (stripos($line, '@phan-forbid-undeclared-magic-methods') !== false) {
                $check_compatible('@phan-forbid-undeclared-magic-methods', [Comment::ON_CLASS]);
                $comment_flags |= Flags::CLASS_FORBID_UNDECLARED_MAGIC_METHODS;
            } else if (stripos($line, '@phan-') !== false && preg_match('/@phan-\S*/', $line, $match)) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::MisspelledAnnotation,
                    $lineno,
                    $match[0],
                    '@phan-forbid-undeclared-magic-methods @phan-forbid-undeclared-magic-properties'
                );
            }

            if (stripos($line, '@deprecated') !== false) {
                if (preg_match('/@deprecated\b/', $line, $match)) {
                    $comment_flags |= Flags::IS_DEPRECATED;
                }
            }

            if (stripos($line, '@internal') !== false) {
                if (preg_match('/@internal\s/', $line, $match)) {
                    $comment_flags |= Flags::IS_NS_INTERNAL;
                }
            }
        }

        return new Comment(
            $comment_flags,
            $variable_list,
            $parameter_list,
            $template_type_list,
            $inherited_type,
            $return_union_type,
            $suppress_issue_list,
            $magic_property_list,
            $magic_method_list,
            $closure_scope
        );
    }

    /**
     * @return void
     */
    private static function emitInvalidCommentForDeclarationType(
        CodeBase $code_base,
        Context $context,
        string $annotationType,
        int $comment_type,
        int $lineno
    ) {
        Issue::maybeEmit(
            $code_base,
            $context,
            Issue::InvalidCommentForDeclarationType,
            $lineno,
            $annotationType,
            self::NAME_FOR_TYPE[$comment_type]
        );
    }

    /**
     * @param CodeBase $code_base
     * Used for extracting issues.
     *
     * @param Context $context
     * The context in which the comment line appears
     *
     * @param string $line
     * An individual line of a comment
     *
     * @param int $lineno
     * The line number of the element that comment annotates
     *
     * @return UnionType
     * The declared return type
     */
    private static function returnTypeFromCommentLine(
        CodeBase $code_base,
        Context $context,
        string $line,
        int $lineno
    ) {
        $return_union_type_string = '';

        if (preg_match('/@return\s+/', $line)) {
            if (preg_match('/@return\s+(' . UnionType::union_type_regex . '+)/', $line, $match)) {
                $return_union_type_string = $match[1];
            }
            // Not emitting any issues about failing to extract, e.g. `@return - Description of what this returns` is a valid comment.
        }
        $return_union_type = UnionType::fromStringInContext(
            $return_union_type_string,
            $context,
            Type::FROM_PHPDOC
        );

        return $return_union_type;
    }

    /**
     * @param CodeBase $code_base
     * CodeBase, for emitting issues.
     *
     * @param Context $context
     * The context in which the comment line appears
     *
     * @param string $line
     * An individual line of a comment
     *
     * @param bool $is_var
     * True if this is parsing a variable, false if parsing a parameter.
     *
     * @param int $lineno
     * The line number of the element this comment annotates.
     *
     * @return CommentParameter
     * A CommentParameter associated with a line that has a var
     * or param reference.
     *
     * TODO: account for difference between (at)var and (at)param
     */
    private static function parameterFromCommentLine(
        CodeBase $code_base,
        Context $context,
        string $line,
        bool $is_var,
        int $lineno
    ) {
        $match = [];
        if (preg_match('/@(param|var)\s+(' . UnionType::union_type_regex . ')(\s+(\.\.\.)?\s*(\\$' . self::word_regex . '))?/', $line, $match)) {
            $type = $match[2];

            $is_variadic = ($match[29] ?? '') === '...';

            if ($is_var && $is_variadic) {
                $variable_name = '';  // "@var int ...$x" is nonsense and invalid phpdoc.
            } else {
                $variable_name = $match[31] ?? '';
            }

            // If the type looks like a variable name, make it an
            // empty type so that other stuff can match it. We can't
            // just skip it or we'd mess up the parameter order.
            $union_type = null;
            if (0 !== strpos($type, '$')) {
                $union_type =
                    UnionType::fromStringInContext(
                        $type,
                        $context,
                        Type::FROM_PHPDOC
                    );
            } else {
                $union_type = new UnionType();
            }

            return new CommentParameter(
                $variable_name,
                $union_type,
                $is_variadic
            );
        } else {
            // Don't warn about @param $x Description of $x goes here
            // TODO: extract doc comment of @param &$x?
            // TODO: Use the right for the name of the comment parameter?
            //       (don't see a benefit, would create a type if it was (at)var on a function-like)
            if (!preg_match('/@(param|var)\s+(\.\.\.)?\s*(\\$\S+)/', $line)) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::UnextractableAnnotation,
                    $lineno,
                    trim($line)
                );
            }
        }

        return  new CommentParameter('', new UnionType());
    }

    /**
     * @param Context $context
     * The context in which the comment line appears
     *
     * @param string $line
     * An individual line of a comment
     *
     * @return TemplateType|null
     * A generic type identifier or null if a valid type identifier
     * wasn't found.
     */
    private static function templateTypeFromCommentLine(
        Context $context,
        string $line
    ) {
        $match = [];
        if (preg_match('/@template\s+(' . Type::simple_type_regex. ')/', $line, $match)) {
            $template_type_identifier = $match[1];
            return new TemplateType($template_type_identifier);
        }

        return null;
    }

    /**
     * @param Context $context
     * The context in which the comment line appears
     *
     * @param string $line
     * An individual line of a comment
     *
     * @return Option<Type>
     * An optional type overriding the extended type of the class
     */
    private static function inheritsFromCommentLine(
        Context $context,
        string $line
    ) {
        $match = [];
        if (preg_match('/@inherits\s+(' . Type::type_regex . ')/', $line, $match)) {
            $type_string = $match[1];

            $type = new Some(Type::fromStringInContext(
                $type_string,
                $context,
                Type::FROM_PHPDOC
            ));

            return $type;
        }

        return new None();
    }

    /**
     * @param string $line
     * An individual line of a comment
     *
     * @return string
     * An issue name to suppress
     */
    private static function suppressIssueFromCommentLine(
        string $line
    ) : string {
        if (preg_match('/@suppress\s+' . self::word_regex . '/', $line, $match)) {
            return $match[1];
        }

        return '';
    }

    /**
     * Parses a magic method based on https://phpdoc.org/docs/latest/references/phpdoc/tags/method.html
     * @return ?CommentParameter - if null, the phpdoc magic method was invalid.
     */
    private static function magicParamFromMagicMethodParamString(
        Context $context,
        string $param_string,
        int $param_index
    ) {
        $param_string = trim($param_string);
        // Don't support trailing commas, or omitted params. Provide at least one of [type] or [parameter]
        if ($param_string === '') {
            return null;
        }
        // Parse an entry for [type] [parameter] - Assume both of those are optional.
        // https://github.com/phpDocumentor/phpDocumentor2/pull/1271/files - phpdoc allows passing an default value.
        // Phan allows `=.*`, to indicate that a parameter is optional
        // TODO: in another PR, check that optional parameters aren't before required parameters.
        if (preg_match('/^(' . UnionType::union_type_regex . ')?\s*((\.\.\.)\s*)?(\$' . self::word_regex . ')?((\s*=.*)?)$/', $param_string, $param_match)) {
            // Note: a magic method parameter can be variadic, but it can't be pass-by-reference? (No support in __call)
            $union_type_string = $param_match[1];
            $union_type = UnionType::fromStringInContext(
                $union_type_string,
                $context,
                Type::FROM_PHPDOC
            );
            $is_variadic = $param_match[28] === '...';
            $default_str = $param_match[31];
            $has_default_value = $default_str !== '';
            if ($has_default_value) {
                $default_value_repr = trim(explode('=', $default_str, 2)[1]);
                if (strcasecmp($default_value_repr, 'null') === 0) {
                    $union_type = $union_type->nullableClone();
                }
            }
            $var_name = $param_match[30];
            if ($var_name === '') {
                // placeholder names are p1, p2, ...
                $var_name = 'p' . ($param_index + 1);
            }
            return new CommentParameter($var_name, $union_type, $is_variadic, $has_default_value);
        }
        return null;
    }

    /**
     * @param CodeBase $code_base
     * @param Context $context
     * @param string $line
     * An individual line of a comment
     *
     * @return ?CommentMethod
     * magic method with the parameter types, return types, and name.
     */
    private static function magicMethodFromCommentLine(
        CodeBase $code_base,
        Context $context,
        string $line,
        int $lineno
    ) {
        // Note that the type of a property can be left out (@property $myVar) - This is equivalent to @property mixed $myVar
        // TODO: properly handle duplicates...
        // https://phpdoc.org/docs/latest/references/phpdoc/tags/method.html
        // > Going to assume "static" is a magic keyword, based on https://github.com/phpDocumentor/phpDocumentor2/issues/822
        // > TODO: forbid in trait?
        // TODO: finish writing the regex.
        // Syntax:
        //    @method [return type] [name]([[type] [parameter]<, ...>]) [<description>]
        //    Assumes the parameters end at the first ")" after "("
        //    As an exception, allows one level of matching brackets
        //    to support old style arrays such as $x = array(), $x = array(2) (Default values are ignored)
        if (preg_match('/@method(\s+(static))?((\s+(' . UnionType::union_type_regex . '))?)\s+' . self::word_regex . '\s*\((([^()]|\([()]*\))*)\)\s*(.*)/', $line, $match)) {
            $is_static = $match[2] === 'static';
            $return_union_type_string = $match[4];
            if ($return_union_type_string !== '') {
                $return_union_type =
                    UnionType::fromStringInContext(
                        $return_union_type_string,
                        $context,
                        Type::FROM_PHPDOC
                    );
            } else {
                // From https://phpdoc.org/docs/latest/references/phpdoc/tags/method.html
                // > When the intended method does not have a return value then the return type MAY be omitted; in which case 'void' is implied.
                $return_union_type = VoidType::instance(false)->asUnionType();
            }
            $method_name = $match[31];

            $arg_list = trim($match[32]);
            $comment_params = [];
            // Special check if param list has 0 params.
            if ($arg_list !== '') {
                // TODO: Would need to use a different approach if templates were ever supported
                $params_strings = explode(',', $arg_list);
                $failed = false;
                foreach ($params_strings as $i => $param_string) {
                    $param = self::magicParamFromMagicMethodParamString($context, $param_string, $i);
                    if ($param === null) {
                        Issue::maybeEmit(
                            $code_base,
                            $context,
                            Issue::UnextractableAnnotationPart,
                            $lineno,
                            trim($line),
                            $param_string
                        );
                        $failed = true;
                    }
                    $comment_params[] = $param;
                }
                if ($failed) {
                    // Emit everything that was wrong with the parameters of the @method annotation at once, then reject it.
                    return null;
                }
            }

            return new CommentMethod($method_name, $return_union_type, $comment_params, $is_static);
        } else {
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::UnextractableAnnotation,
                $lineno,
                trim($line)
            );
        }

        return null;
    }

    /**
     * @param Context $context
     * @param string $line
     * An individual line of a comment
     * Currently treats property-read and property-write the same way
     * because of the rewrites required for read-only properties.
     *
     * @return CommentParameter|null
     * magic property with the union type.
     */
    private static function magicPropertyFromCommentLine(
        CodeBase $code_base,
        Context $context,
        string $line,
        int $lineno
    ) {
        // Note that the type of a property can be left out (@property $myVar) - This is equivalent to @property mixed $myVar
        // TODO: properly handle duplicates...
        // TODO: support read-only/write-only checks elsewhere in the codebase?
        if (preg_match('/@(property|property-read|property-write)(\s+' . UnionType::union_type_regex . ')?(\s+(\\$' . self::word_regex . '))/', $line, $match)) {
            $type = ltrim($match[2] ?? '');

            $property_name = $match[30] ?? '';
            if ($property_name === '') {
                return null;
            }

            // If the type looks like a property name, make it an
            // empty type so that other stuff can match it.
            $union_type = null;
            if (0 !== strpos($type, '$')) {
                $union_type =
                    UnionType::fromStringInContext(
                        $type,
                        $context,
                        Type::FROM_PHPDOC
                    );
            } else {
                $union_type = new UnionType();
            }

            return new CommentParameter(
                $property_name,
                $union_type
            );
        } else {
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::UnextractableAnnotation,
                $lineno,
                trim($line)
            );
        }

        return null;
    }

    /**
     * The context in which the comment line appears
     *
     * @param string $line
     * An individual line of a comment
     *
     * @return Option<Type>
     * A class/interface to use as a context for a closure.
     * (Phan expects a ClassScope to have exactly one type)
     */
    private static function getPhanClosureScopeFromCommentLine(
        Context $context,
        string $line
    ) : Option {
        $closure_scope_union_type_string = '';

        // https://secure.php.net/manual/en/closure.bindto.php
        // There wasn't anything in the phpdoc standard to indicate the class to which
        // a Closure would be bound with bind() or bindTo(), so using a custom tag.
        //
        // TODO: Also add a version which forbids using $this in the closure?
        if (preg_match('/@PhanClosureScope\s+(' . UnionType::union_type_regex . '+)/', $line, $match)) {
            $closure_scope_union_type_string = $match[1];
        }

        if ($closure_scope_union_type_string !== '') {
            return new Some(Type::fromStringInContext(
                $closure_scope_union_type_string,
                $context,
                Type::FROM_PHPDOC
            ));
        }
        return new None();
    }

    /**
     * @return bool
     * Set to true if the comment contains a 'deprecated'
     * directive.
     */
    public function isDeprecated() : bool
    {
        return ($this->comment_flags & Flags::IS_DEPRECATED) != 0;
    }

    /**
     * @return bool
     * Set to true if the comment contains an 'internal'
     * directive.
     */
    public function isNSInternal() : bool
    {
        return ($this->comment_flags & Flags::IS_NS_INTERNAL) != 0;
    }

    /**
     * @return bool
     * Set to true if the comment contains a 'phan-forbid-undeclared-magic-properties'
     * directive.
     */
    public function getForbidUndeclaredMagicProperties() : bool
    {
        return ($this->comment_flags & Flags::CLASS_FORBID_UNDECLARED_MAGIC_PROPERTIES) != 0;
    }

    /**
     * @return bool
     * Set to true if the comment contains a 'phan-forbid-undeclared-magic-methods'
     * directive.
     */
    public function getForbidUndeclaredMagicMethods() : bool
    {
        return ($this->comment_flags & Flags::CLASS_FORBID_UNDECLARED_MAGIC_METHODS) != 0;
    }

    /**
     * @return UnionType
     * A UnionType defined by a (at)return directive
     */
    public function getReturnType() : UnionType
    {
        return $this->return_union_type;
    }

    /**
     * @return bool
     * True if this doc block contains a (at)return
     * directive specifying a type.
     */
    public function hasReturnUnionType() : bool
    {
        return !$this->return_union_type->isEmpty();
    }

    /**
     * @return Option<Type>
     * An optional Type defined by a (at)PhanClosureScope
     * directive specifying a single type.
     */
    public function getClosureScopeOption() : Option
    {
        return $this->closure_scope;
    }

    /**
     * @return CommentParameter[] (The leftover parameters without a name)
     *
     * @suppress PhanUnreferencedMethod
     */
    public function getParameterList() : array
    {
        return $this->parameter_list;
    }

    /**
     * @return CommentParameter[] (maps the names of parameters to their values. Does not include parameters which didn't provide names)
     *
     * @suppress PhanUnreferencedMethod
     */
    public function getParameterMap() : array
    {
        return $this->parameter_map;
    }

    /**
     * @return TemplateType[]
     * A list of template types parameterizing a generic class
     */
    public function getTemplateTypeList() : array
    {
        return $this->template_type_list;
    }

    /**
     * @return Option<Type>
     * An optional type declaring what a class extends.
     */
    public function getInheritedTypeOption() : Option
    {
        return $this->inherited_type;
    }

    /**
     * @return string[]
     * A set of issue names like 'PhanUnreferencedMethod' to suppress
     */
    public function getSuppressIssueList() : array
    {
        return $this->suppress_issue_list;
    }

    /**
     * @return bool
     * True if we have a parameter at the given offset
     */
    public function hasParameterWithNameOrOffset(
        string $name,
        int $offset
    ) : bool {
        if (!empty($this->parameter_map[$name])) {
            return true;
        }

        return !empty($this->parameter_list[$offset]);
    }

    /**
     * @return CommentParameter
     * The paramter at the given offset
     */
    public function getParameterWithNameOrOffset(
        string $name,
        int $offset
    ) : CommentParameter {
        if (!empty($this->parameter_map[$name])) {
            return $this->parameter_map[$name];
        }

        return $this->parameter_list[$offset];
    }

    /**
     * @unused
     * @return bool
     * True if we have a magic property with the given name
     */
    public function hasMagicPropertyWithName(
        string $name
    ) : bool {
        return isset($this->magic_property_map[$name]);
    }

    /**
     * @unused
     * @return CommentParameter
     * The magic property with the given name. May or may not have a type.
     */
    public function getMagicPropertyWithName(
        string $name
    ) : CommentParameter {
        return $this->magic_property_map[$name];
    }

    /**
     * @return CommentParameter[] map from parameter name to parameter
     */
    public function getMagicPropertyMap() : array {
        return $this->magic_property_map;
    }

    /**
     * @return CommentMethod[] map from method name to method info
     */
    public function getMagicMethodMap() : array {
        return $this->magic_method_map;
    }

    /**
     * @return CommentParameter[]
     */
    public function getVariableList() : array
    {
        return $this->variable_list;
    }

    public function __toString() : string
    {
        // TODO: add new properties of Comment to this method
        // (magic methods, magic properties, custom @phan directives, etc.))
        $string = "/**\n";

        if (($this->comment_flags & Flags::IS_DEPRECATED) != 0) {
            $string  .= " * @deprecated\n";
        }

        foreach ($this->variable_list as $variable) {
            $string  .= " * @var $variable\n";
        }

        foreach ($this->parameter_list as $parameter) {
            $string  .= " * @param $parameter\n";
        }

        if ($this->return_union_type) {
            $string .= " * @return {$this->return_union_type}\n";
        }

        $string .= " */\n";

        return $string;
    }
}
