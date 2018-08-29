<?php
declare(strict_types=1);
namespace Phan\Language\Element;

use Phan\CodeBase;
use Phan\Config;
use Phan\Language\Context;
use Phan\Language\Element\Comment\Builder;
use Phan\Language\Element\Comment\Parameter as CommentParameter;
use Phan\Language\Element\Comment\Method as CommentMethod;
use Phan\Language\Element\Flags;
use Phan\Language\Type;
use Phan\Language\Type\TemplateType;
use Phan\Language\UnionType;
use Phan\Library\None;
use Phan\Library\Option;

/**
 * Handles extracting information(param types, return types, magic methods/properties, etc.) from phpdoc comments.
 * Instances of Comment contain the extracted information.
 *
 * TODO: Pass the doccomment line's index to the Element that will use the client,
 * so that it can be used for more precise line numbers (E.g. for where magic methods were declared,
 * where functions with no signature types but phpdoc types declared types that are invalid class names, etc.
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

    // List of types that are function-like (e.g. have params and function body)
    const FUNCTION_LIKE = [
        self::ON_METHOD,
        self::ON_FUNCTION,
    ];

    // Lists of types that can have (at)var annotations.
    const HAS_VAR_ANNOTATION = [
        self::ON_METHOD,
        self::ON_FUNCTION,
        self::ON_VAR,
        self::ON_PROPERTY,
        self::ON_CONST,
    ];

    const NAME_FOR_TYPE = [
        self::ON_CLASS      => 'class',
        self::ON_VAR        => 'variable',
        self::ON_PROPERTY   => 'property',
        self::ON_CONST      => 'constant',
        self::ON_METHOD     => 'method',
        self::ON_FUNCTION   => 'function',
    ];

    /**
     * @deprecated use Builder::WORD_REGEX
     */
    const WORD_REGEX = '([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)';

    /**
     * @var int - contains a subset of flags to set on elements
     * Flags::CLASS_FORBID_UNDECLARED_MAGIC_PROPERTIES
     * Flags::CLASS_FORBID_UNDECLARED_MAGIC_METHODS
     * Flags::IS_DEPRECATED
     */
    private $comment_flags = 0;

    /**
     * @var array<int,CommentParameter>
     * A list of CommentParameters from var declarations
     */
    private $variable_list = [];

    /**
     * @var array<int,CommentParameter>
     * A list of CommentParameters from param declarations
     */
    private $parameter_list = [];

    /**
     * @var array<string,CommentParameter>
     * A map from variable name to CommentParameters from
     * param declarations
     */
    private $parameter_map = [];

    /**
     * @var array<int,TemplateType>
     * A list of template types parameterizing a generic class
     */
    private $template_type_list = [];

    /**
     * @var Option<Type>|None
     * Classes may specify their inherited type explicitly
     * via `(at)inherits Type`.
     */
    private $inherited_type;

    /**
     * @var UnionType|null
     * A UnionType defined by an (at)return directive
     */
    private $return_union_type = null;

    /**
     * @var array<int,string>
     * A list of issue types to be suppressed
     */
    private $suppress_issue_list = [];

    /**
     * @var array<string,CommentParameter>
     * A mapping from magic property parameters to types.
     */
    private $magic_property_map = [];

    /**
     * @var array<string,CommentMethod>
     * A mapping from magic methods to parsed parameters, name, and return types.
     */
    private $magic_method_map = [];

    /**
     * @var UnionType a list of types for (at)throws annotations
     */
    private $throw_union_type;

    /**
     * @var Option<Type>|None
     * An optional class name defined by an (at)phan-closure-scope directive.
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
     * @param array<int,CommentParameter> $variable_list
     *
     * @param array<int,CommentParameter> $parameter_list
     *
     * @param array<int,TemplateType> $template_type_list
     * A list of template types parameterizing a generic class
     *
     * @param Option<Type>|None $inherited_type (Note: some issues with templates and narrowing signature types to phpdoc type, added None as a workaround)
     * An override on the type of the extended class
     *
     * @param UnionType $return_union_type
     *
     * @param array<int,string> $suppress_issue_list
     * A list of tags for error type to be suppressed
     *
     * @param array<int,CommentParameter> $magic_property_list
     *
     * @param array<int,CommentMethod> $magic_method_list
     *
     * @param array<string,mixed> $phan_overrides
     *
     * @param Option<Type>|None $closure_scope
     * For closures: Allows us to document the class of the object
     * to which a closure will be bound.
     *
     * @param UnionType $throw_union_type
     * @internal
     */
    public function __construct(
        int $comment_flags,
        array $variable_list,
        array $parameter_list,
        array $template_type_list,
        Option $inherited_type,
        UnionType $return_union_type,
        array $suppress_issue_list,
        array $magic_property_list,
        array $magic_method_list,
        array $phan_overrides,
        Option $closure_scope,
        UnionType $throw_union_type
    ) {
        $this->comment_flags = $comment_flags;
        $this->variable_list = $variable_list;
        $this->parameter_list = $parameter_list;
        $this->template_type_list = $template_type_list;
        $this->inherited_type = $inherited_type;
        $this->return_union_type = $return_union_type;
        $this->suppress_issue_list = $suppress_issue_list;
        $this->closure_scope = $closure_scope;
        $this->throw_union_type = $throw_union_type;

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
        foreach ($phan_overrides as $key => $override_value) {
            $this->applyOverride($key, $override_value);
        }
    }

    /**
     * @param mixed $value
     * @return void
     */
    private function applyOverride(string $key, $value)
    {
        switch ($key) {
            case 'param':
                foreach ($value as $parameter) {
                    $name = $parameter->getName();
                    if (!empty($name)) {
                        // Add it to the named map
                        // TODO: could check that @phan-param is compatible with the original @param
                        $this->parameter_map[$name] = $parameter;
                    }
                }
                return;
            case 'return':
                // TODO: could check that @phan-return is compatible with the original @return
                $this->return_union_type = $value;
                return;
            case 'var':
                // TODO: Remove pre-existing entries.
                $this->mergeVariableList($value);
                return;
            case 'property':
                foreach ($value as $property) {
                    $name = $property->getName();
                    if (!empty($name)) {
                        // Override or add the entry in the named map
                        $this->magic_property_map[$name] = $property;
                    }
                }
                return;
            case 'method':
                foreach ($value as $method) {
                    $name = $method->getName();
                    if (!empty($name)) {
                        // Override or add the entry in the named map
                        $this->magic_method_map[$name] = $method;
                    }
                }
                return;
        }
    }

    /**
     * @var array<int,CommentParameter>
     * A list of CommentParameters from var declarations
     */
    private function mergeVariableList(array $override_comment_vars)
    {
        $known_names = [];
        foreach ($override_comment_vars as $override_var) {
            $known_names[$override_var->getName()] = true;
        }
        foreach ($this->variable_list as $i => $var) {
            if (isset($known_names[$var->getName()])) {
                unset($this->variable_list[$i]);
            }
        }
        $this->variable_list = array_merge($this->variable_list, $override_comment_vars);
    }


    /**
     * @param string $comment full text of doc comment
     * @param CodeBase $code_base
     * @param Context $context
     * @param int $comment_type self::ON_* (the type of comment this is)
     * @return Comment
     * A comment built by parsing the given doc block
     * string.
     *
     * suppress PhanTypeMismatchArgument - Still need to work out issues with prefer_narrowed_phpdoc_param_type
     */
    public static function fromStringInContext(
        string $comment,
        CodeBase $code_base,
        Context $context,
        int $lineno,
        int $comment_type
    ) : Comment {

        // Don't parse the comment if this doesn't need to.
        if (!$comment || !Config::getValue('read_type_annotations') || \strpos($comment, '@') === false) {
            return new Comment(
                0,
                [],
                [],
                [],
                new None(),
                UnionType::empty(),
                [],
                [],
                [],
                [],
                new None(),
                UnionType::empty()
            );
        }

        // @phan-suppress-next-line PhanAccessMethodInternal
        return (new Builder(
            $comment,
            $code_base,
            $context,
            $lineno,
            $comment_type
        ))->build();
    }

    // TODO: Is `@return &array` valid phpdoc2?

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
     * Set to true if the comment contains an 'override'
     * directive.
     */
    public function isOverrideIntended() : bool
    {
        return ($this->comment_flags & Flags::IS_OVERRIDE_INTENDED) != 0;
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
     * Sets A UnionType defined by a (at)return directive
     * @return void
     * @suppress PhanUnreferencedPublicMethod not used right now, but making it available for plugins
     */
    public function setReturnType(UnionType $return_union_type)
    {
        $this->return_union_type = $return_union_type;
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
     *
     * @suppress PhanPartialTypeMismatchReturn (Null)
     */
    public function getClosureScopeOption() : Option
    {
        return $this->closure_scope;
    }

    /**
     * @return array<int,CommentParameter> (The leftover parameters without a name)
     *
     * @suppress PhanUnreferencedPublicMethod
     */
    public function getParameterList() : array
    {
        return $this->parameter_list;
    }

    /**
     * @return array<string,CommentParameter> (maps the names of parameters to their values. Does not include parameters which didn't provide names)
     *
     * @suppress PhanUnreferencedPublicMethod
     * @suppress PhanPartialTypeMismatchReturn (Null)
     */
    public function getParameterMap() : array
    {
        return $this->parameter_map;
    }

    /**
     * @return array<int,TemplateType>
     * A list of template types parameterizing a generic class
     */
    public function getTemplateTypeList() : array
    {
        return $this->template_type_list;
    }

    /**
     * @return Option<Type>
     * An optional type declaring what a class extends.
     * @suppress PhanPartialTypeMismatchReturn (Null)
     */
    public function getInheritedTypeOption() : Option
    {
        return $this->inherited_type;
    }

    /**
     * @return array<int,string>
     * A set of issue names like 'PhanUnreferencedPublicMethod' to suppress
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
     * The parameter at the given offset
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
     * @return CommentParameter
     * The magic property with the given name. May or may not have a type.
     * @unused
     * @suppress PhanUnreferencedPublicMethod not used right now, but making it available for plugins
     */
    public function getMagicPropertyWithName(
        string $name
    ) : CommentParameter {
        return $this->magic_property_map[$name];
    }

    /**
     * @return array<string,CommentParameter> map from parameter name to parameter
     */
    public function getMagicPropertyMap() : array
    {
        return $this->magic_property_map;
    }

    /**
     * @return array<string,CommentMethod> map from method name to method info
     */
    public function getMagicMethodMap() : array
    {
        return $this->magic_method_map;
    }

    /**
     * @return UnionType list of types for throws statements
     */
    public function getThrowsUnionType() : UnionType
    {
        return $this->throw_union_type;
    }

    /**
     * @return array<int,CommentParameter>
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
        foreach ($this->throw_union_type->getTypeSet() as $type) {
            $string .= " * @throws {$type}\n";
        }

        $string .= " */\n";

        return $string;
    }
}
