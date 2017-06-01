<?php declare(strict_types=1);
namespace Phan\Language;

use Phan\CodeBase;
use Phan\Config;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\Type\ArrayType;
use Phan\Language\Type\BoolType;
use Phan\Language\Type\CallableType;
use Phan\Language\Type\FloatType;
use Phan\Language\Type\GenericArrayType;
use Phan\Language\Type\IntType;
use Phan\Language\Type\IterableType;
use Phan\Language\Type\MixedType;
use Phan\Language\Type\NativeType;
use Phan\Language\Type\NullType;
use Phan\Language\Type\ObjectType;
use Phan\Language\Type\ResourceType;
use Phan\Language\Type\StaticType;
use Phan\Language\Type\StringType;
use Phan\Language\Type\TemplateType;
use Phan\Language\Type\VoidType;
use Phan\Library\None;
use Phan\Library\Option;
use Phan\Library\Some;
use Phan\Library\Tuple4;

class Type
{
    use \Phan\Memoize;

    /**
     * @var string
     * A legal type identifier (e.g. 'int' or 'DateTime')
     */
    const simple_type_regex =
        '(\??)[a-zA-Z_\x7f-\xff\\\][a-zA-Z0-9_\x7f-\xff\\\]*';

    /**
     * @var string
     * A regex matching template parameter types such
     * as '<int,DateTime|null,string>'
     */
    const template_parameter_type_list_regex =
        '<'
        . '('
        . '(' . self::simple_type_regex . '(\[\])*' . ')'
        . '(' . '\s*,\s*'
        . '(' . self::simple_type_regex . '(\[\])*' . ')'
        . ')*'
        . ')'
        . '>';

    /**
     * @var string
     * A type with an optional template parameter list
     * such as 'Set<Datetime>', 'int' or 'Tuple2<int>'.
     */
    const simple_type_with_template_parameter_list_regex =
        '(' . self::simple_type_regex . ')'
        . '(' . self::template_parameter_type_list_regex . ')?';

    /**
     * @var string
     * A legal type identifier matching a type optionally with a []
     * indicating that it's a generic typed array (e.g. 'int[]',
     * 'string' or 'Set<DateTime>')
     * TODO: change the regex so that '(at)return $this' will work (Currently not parsed, has empty regex)
     */
    const type_regex =
        self::simple_type_with_template_parameter_list_regex . '(\[\])*';

    /**
     * @var bool[] - For checking if a string is an internal type.
     */
    const _internal_type_set = [
        'array'     => true,
        'bool'      => true,
        'callable'  => true,
        'float'     => true,
        'int'       => true,
        'iterable'  => true,
        'mixed'     => true,
        'null'      => true,
        'object'    => true,
        'resource'  => true,
        'static'    => true,
        'string'    => true,
        'void'      => true,
    ];

    /**
     * These can currently be used in phpdoc but not real types.
     * This is a subset of self::_internal_type_set
     *
     * https://secure.php.net/manual/en/reserved.other-reserved-words.php
     * > The following list of words have had soft reservations placed on them.
     * > Whilst they may still be used as class, interface, and trait names (as well as in namespaces),
     * > usage of them is highly discouraged since they may be used in future versions of PHP.
     *
     * (numeric not supported yet)
     */
    const _soft_internal_type_set = [
        'mixed'     => true,
        'object'    => true,
        'resource'  => true,
    ];

    // Distinguish between multiple ways types can be created.
    // e.g. integer and resource are phpdoc types, but they aren't actual types.

    /** For types created from a type in an AST node, e.g. `int $x` */
    const FROM_NODE = 0;

    /** For types copied from another type, e.g. `$x = $y` gets types from $y */
    const FROM_TYPE = 1;

    /** For types copied from phpdoc, e.g. `(at)param integer $x` */
    const FROM_PHPDOC = 2;

    /**
     * @var string|null
     * The namespace of this type such as '\' or
     * '\Phan\Language'
     */
    protected $namespace = null;

    /**
     * @var string|null
     * The name of this type such as 'int' or 'MyClass'
     */
    protected $name = null;

    /**
     * @var UnionType[]
     * A possibly empty list of concrete types that
     * act as parameters to this type if it is a templated
     * type.
     */
    protected $template_parameter_type_list = [];

    /**
     * @var bool
     * True if this type is nullable, else false
     */
    protected $is_nullable = false;

    /**
     * @param string $name
     * The name of the type such as 'int' or 'MyClass'
     *
     * @param string $namespace
     * The (optional) namespace of the type such as '\'
     * or '\Phan\Language'.
     *
     * @param UnionType[] $template_parameter_type_list
     * A (possibly empty) list of template parameter types
     *
     * @param bool $is_nullable
     * True if this type can be null, false if it cannot
     * be null.
     */
    protected function __construct(
        string $namespace,
        string $name,
        $template_parameter_type_list,
        bool $is_nullable
    ) {
        $this->namespace = $namespace;
        $this->name = $name;
        $this->template_parameter_type_list = $template_parameter_type_list;
        $this->is_nullable = $is_nullable;
    }

    /**
     * @param string $namespace
     * The (optional) namespace of the type such as '\'
     * or '\Phan\Language'.
     *
     * @param string $type_name
     * The name of the type such as 'int' or 'MyClass'
     *
     * @param UnionType[] $template_parameter_type_list
     * A (possibly empty) list of template parameter types
     *
     * @param bool $is_nullable
     * True if this type can be null, false if it cannot
     * be null.
     *
     * @param int $source
     * Type::FROM_NODE, Type::FROM_TYPE, or Type::FROM_PHPDOC
     * (E.g. outside of phpdoc, "integer" would be a class name)
     *
     * @return Type
     * A single canonical instance of the given type.
     */
    protected static function make(
        string $namespace,
        string $type_name,
        $template_parameter_type_list,
        bool $is_nullable,
        int $source
    ) : Type {

        $namespace = trim($namespace);

        if ('\\' === $namespace && $source) {
            $type_name = self::canonicalNameFromName($type_name);
        }

        // If this looks like a generic type string, explicitly
        // make it as such
        if (self::isGenericArrayString($type_name)
            && ($pos = strrpos($type_name, '[]')) !== false
        ) {
            return GenericArrayType::fromElementType(Type::make(
                $namespace,
                substr($type_name, 0, $pos),
                $template_parameter_type_list,
                false,
                $source
            ), $is_nullable);
        }

        assert(
            !empty($namespace),
            "Namespace cannot be empty"
        );

        assert(
            '\\' === $namespace[0],
            "Namespace must be fully qualified"
        );

        assert(
            !empty($type_name),
            "Type name cannot be empty"
        );

        assert(
            false === strpos(
                $type_name,
                '|'
            ),
            "Type name may not contain a pipe."
        );

        // Create a canonical representation of the
        // namespace and name
        $namespace = $namespace ?: '\\';
        if ('\\' === $namespace && $source === Type::FROM_PHPDOC) {
            $type_name = self::canonicalNameFromName($type_name);
        }

        // Make sure we only ever create exactly one
        // object for any unique type
        $key = ($is_nullable ? '?' : '') . $namespace . '\\' . $type_name;

        if ($template_parameter_type_list) {
            $key .= '<' . implode(',', array_map(function (UnionType $union_type) {
                return (string)$union_type;
            }, $template_parameter_type_list)) . '>';
        }

        $key = strtolower($key);

        return static::cachedGetInstanceHelper($namespace, $type_name, $template_parameter_type_list, $is_nullable, $key, false);
    }

    /**
     * @return static
     * @see static::__construct
     */
    protected static final function cachedGetInstanceHelper(
        string $namespace,
        string $name,
        $template_parameter_type_list,
        bool $is_nullable,
        string $key,
        bool $clear_all_memoize
    ) : Type {
        // TODO: Figure out why putting this into a static variable results in test failures.
        static $canonical_object_map = [];
        if ($clear_all_memoize) {
            foreach ($canonical_object_map as $type) {
                $type->memoizeFlushAll();
            }
            return NullType::instance(false);  // dummy
        }
        $value = $canonical_object_map[$key] ?? null;
        if (!$value) {
            $value =
                new static(
                    $namespace,
                    $name,
                    $template_parameter_type_list,
                    $is_nullable
                );
            $canonical_object_map[$key] = $value;
        }
        return $value;
    }

    /**
     * Call this before forking and analysis phase, when in daemon mode.
     * This may hurt performance.
     *
     * It's important to clear asExpandedTypes(),
     * as the parent classes may have changed since the last parse attempt.
     *
     * @return void
     */
    public static function clearAllMemoizations() {
        // Clear anything that has memoized state
        Type::cachedGetInstanceHelper('', '', [], false, '', true);
        TemplateType::cachedGetInstanceHelper('', '', [], false, '', true);
        GenericArrayType::cachedGetInstanceHelper('', '', [], false, '', true);
    }


    /**
     * @param Type $type
     * The base type of this generic type referencing a
     * generic class
     *
     * @param UnionType[] $template_parameter_type_list
     * A map from a template type identifier to a
     * concrete union type
     */
    public static function fromType(
        Type $type,
        $template_parameter_type_list
    ) : Type {
        return self::make(
            $type->getNamespace(),
            $type->getName(),
            $template_parameter_type_list,
            $type->getIsNullable(),
            Type::FROM_TYPE
        );
    }

    /**
     * If the $name is a reserved constant, then returns the NativeType for that name
     * Otherwise, this returns null.
     * @return Option<NativeType>
     */
    public static function fromReservedConstantName(string $name) : Option {
        static $lookup;
        if ($lookup === null) {
            $lookup = self::createReservedConstantNameLookup();
        }
        $result = $lookup[strtoupper(ltrim($name, '\\'))] ?? null;
        if (isset($result)) {
            return new Some($result);
        }
        return new None;
    }

    /**
     * @return NativeType[] a map from the **uppercase** reserved constant name to the subclass of NativeType for that constant.
     * Uses the constants and types from https://secure.php.net/manual/en/reserved.constants.php
     */
    private static function createReservedConstantNameLookup() : array {
        $int = IntType::instance(false);
        $bool = BoolType::instance(false);
        $null = NullType::instance(false);
        $string = StringType::instance(false);
        $float  = FloatType::instance(false);
        return [
            'PHP_VERSION'           => $string,
            'PHP_MAJOR_VERSION'     => $int,
            'PHP_MINOR_VERSION'     => $int,
            'PHP_RELEASE_VERSION'   => $int,
            'PHP_VERSION_ID'        => $int,
            'PHP_EXTRA_VERSION'     => $string,
            'PHP_ZTS'               => $int,
            'PHP_MAXPATHLEN'        => $int,
            'PHP_OS'                => $string,
            'PHP_OS_FAMILY'         => $string,
            'PHP_SAPI'              => $string,
            'PHP_EOL'               => $string,
            'PHP_INT_MAX'           => $int,
            'PHP_INT_MIN'           => $int,  // since 7.0.0
            'PHP_INT_SIZE'          => $int,  // since 7.0.0
            //'PHP_FLOAT_DIG'         => $int,  // since 7.2.0
            //'PHP_FLOAT_EPSILON'     => $float,  // since 7.2.0
            //'PHP_FLOAT_MIN'         => $int, // since 7.2.0
            //'PHP_FLOAT_MAX'         => $int, // since 7.2.0
            'DEFAULT_INCLUDE_PATH'  => $string,
            'PEAR_INSTALL_DIR'      => $string,
            'PEAR_EXTENSION_DIR'    => $string,
            'PHP_EXTENSION_DIR'     => $string,
            'PEAR_EXTENSION_DIR'    => $string,
            'PHP_PREFIX'            => $string,
            'PHP_BINDIR'            => $string,
            'PHP_BINARY'            => $string,
            'PHP_MANDIR'            => $string,
            'PHP_LIBDIR'            => $string,
            'PHP_DATADIR'           => $string,
            'PHP_SYSCONFDIR'        => $string,
            'PHP_LOCALSTATEDIR'     => $string,
            'PHP_CONFIG_FILE_PATH'  => $string,
            'PHP_CONFIG_FILE_SCAN_DIR' => $string,
            'PHP_SHLIB_SUFFIX'      => $string,
            //'PHP_FD_SETSIZE'            => $int,  // 7.2.0 TODO: web page documentation is wrong, says string.
            'E_ERROR'               => $int,
            'E_WARNING'             => $int,
            'E_PARSE'               => $int,
            'E_NOTICE'              => $int,
            'E_CORE_ERROR'          => $int,
            'E_CORE_WARNING'        => $int,
            'E_COMPILE_ERROR'       => $int,
            'E_COMPILE_WARNING'     => $int,
            'E_USER_ERROR'          => $int,
            'E_USER_WARNING'        => $int,
            'E_USER_NOTICE'         => $int,
            'E_DEPRECATED'          => $int,
            'E_USER_DEPRECATED'     => $int,
            'E_ALL'                 => $int,
            'E_STRICT'              => $int,
            '__COMPILER_HALT_OFFSET__' => $int,
            'TRUE'                  => $bool,
            'FALSE'                 => $bool,
            'NULL'                  => $null,
        ];
    }

    /**
     * @return Type
     * Get a type for the given object
     */
    public static function fromObject($object) : Type
    {
        // gettype(2) doesn't return 'int', it returns 'integer', so use FROM_PHPDOC
        return Type::fromInternalTypeName(gettype($object), false, self::FROM_PHPDOC);
    }

    /**
     * @param string $type_name
     * The name of the internal type such as 'int'
     *
     * @param bool $is_nullable
     * Set to true if the type should be nullable, else pass
     * false
     *
     * @param int $source Type::FROM_*
     *
     * @return Type
     * Get a type for the given type name
     */
    public static function fromInternalTypeName(
        string $type_name,
        bool $is_nullable,
        int $source
    ) : Type {

        // If this is a generic type (like int[]), return
        // a generic of internal types.
        //
        // When there's a nullability operator such as in
        // `?int[]`, it applies to the array rather than
        // the int
        if (false !== ($pos = strrpos($type_name, '[]'))) {
            return GenericArrayType::fromElementType(
                self::fromInternalTypeName(
                    substr($type_name, 0, $pos),
                    false,
                    $source
                ),
                $is_nullable
            );
        }

        $type_name =
            self::canonicalNameFromName($type_name);

        switch (strtolower($type_name)) {
            case 'array':
                return ArrayType::instance($is_nullable);
            case 'bool':
                return BoolType::instance($is_nullable);
            case 'callable':
                return CallableType::instance($is_nullable);
            case 'float':
                return FloatType::instance($is_nullable);
            case 'int':
                return IntType::instance($is_nullable);
            case 'mixed':
                return MixedType::instance($is_nullable);
            case 'null':
                return NullType::instance($is_nullable);
            case 'object':
                return ObjectType::instance($is_nullable);
            case 'resource':
                return ResourceType::instance($is_nullable);
            case 'string':
                return StringType::instance($is_nullable);
            case 'void':
                // TODO: This can't be nullable, right?
                return VoidType::instance($is_nullable);
            case 'iterable':
                return IterableType::instance($is_nullable);
            case 'static':
                return StaticType::instance($is_nullable);
        }

        assert(
            false,
            "No internal type with name $type_name"
        );
    }

    /**
     * @param string $namespace
     * A fully qualified namespace
     *
     * @param string $type_name
     * The name of the type
     *
     * @return Type
     * A type representing the given namespace and type
     * name.
     *
     * @param bool $is_nullable
     * True if this type can be null, false if it cannot
     * be null.
     */
    public static function fromNamespaceAndName(
        string $namespace,
        string $type_name,
        bool  $is_nullable
    ) : Type {
        return self::make($namespace, $type_name, [], $is_nullable, Type::FROM_NODE);
    }

    public static function fromReflectionType(
        \ReflectionType $reflection_type
    ) : Type {

        return self::fromStringInContext(
            (string)$reflection_type,
            new Context(),
            Type::FROM_NODE
        );
    }

    /**
     * @param string $fully_qualified_string
     * A fully qualified type name
     *
     * @return Type
     */
    public static function fromFullyQualifiedString(
        string $fully_qualified_string
    ) : Type {

        assert(
            !empty($fully_qualified_string),
            "Type cannot be empty"
        );

        $tuple = self::typeStringComponents($fully_qualified_string);

        $namespace = $tuple->_0;
        $type_name = $tuple->_1;
        $template_parameter_type_name_list = $tuple->_2;
        $is_nullable = $tuple->_3;

        if (empty($namespace)) {
            return self::fromInternalTypeName(
                $fully_qualified_string,
                $is_nullable,
                Type::FROM_NODE
            );
        }

        // Map the names of the types to actual types in the
        // template parameter type list
        $template_parameter_type_list = array_map(function (string $type_name) {
            return Type::fromFullyQualifiedString($type_name)->asUnionType();
        }, $template_parameter_type_name_list);

        if (0 !== strpos($namespace, '\\')) {
            $namespace = '\\' . $namespace;
        }

        assert(
            !empty($namespace) && !empty($type_name),
            "Type was not fully qualified"
        );

        return self::make(
            $namespace,
            $type_name,
            $template_parameter_type_list,
            $is_nullable,
            Type::FROM_NODE
        );
    }

    /**
     * @param string $string
     * A string representing a type
     *
     * @param Context $context
     * The context in which the type string was
     * found
     *
     * @param int $source
     * Type::FROM_NODE, Type::FROM_TYPE, or Type::FROM_PHPDOC
     *
     * @return Type
     * Parse a type from the given string
     */
    public static function fromStringInContext(
        string $string,
        Context $context,
        int $source
    ) : Type {

        assert(
            $string !== '',
            "Type cannot be empty"
        );

        // Extract the namespace, type and parameter type name list
        $tuple = self::typeStringComponents($string);

        $namespace = $tuple->_0;
        $type_name = $tuple->_1;
        $template_parameter_type_name_list = $tuple->_2;
        $is_nullable = $tuple->_3;

        // Map the names of the types to actual types in the
        // template parameter type list
        $template_parameter_type_list =
            array_map(function (string $type_name) use ($context, $source) {
                return Type::fromStringInContext($type_name, $context, $source)->asUnionType();
            }, $template_parameter_type_name_list);

        // @var bool
        // True if this type name if of the form 'C[]'
        $is_generic_array_type =
            self::isGenericArrayString($type_name);

        // If this is a generic array type, get the name of
        // the type of each element
        $non_generic_array_type_name = $type_name;
        if ($is_generic_array_type
           && false !== ($pos = strrpos($type_name, '[]'))
        ) {
            $non_generic_array_type_name =
                substr($type_name, 0, $pos);
        }

        // Check to see if the type name is mapped via
        // a using clause.
        //
        // Gotta check this before checking for native types
        // because there are monsters out there that will
        // remap the names via things like `use \Foo\String`.
        $non_generic_partially_qualified_array_type_name =
            $non_generic_array_type_name;
        if ($namespace) {
            $non_generic_partially_qualified_array_type_name =
                $namespace . '\\' . $non_generic_partially_qualified_array_type_name;
        }
        if ($context->hasNamespaceMapFor(
            \ast\flags\USE_NORMAL,
            $non_generic_partially_qualified_array_type_name
        )) {
            $fqsen =
                $context->getNamespaceMapFor(
                    \ast\flags\USE_NORMAL,
                    $non_generic_partially_qualified_array_type_name
                );

            if ($is_generic_array_type) {
                return GenericArrayType::fromElementType(Type::make(
                    $fqsen->getNamespace(),
                    $fqsen->getName(),
                    $template_parameter_type_list,
                    false,
                    $source
                ), $is_nullable);
            }

            return Type::make(
                $fqsen->getNamespace(),
                $fqsen->getName(),
                $template_parameter_type_list,
                $is_nullable,
                $source
            );
        }

        // If this was a fully qualified type, we're all
        // set
        if (!empty($namespace) && 0 === strpos($namespace, '\\')) {
            return self::make(
                $namespace,
                $type_name,
                $template_parameter_type_list,
                $is_nullable,
                $source
            );
        }

        if (self::isInternalTypeString($type_name, $source)) {
            return self::fromInternalTypeName($type_name, $is_nullable, $source);
        }

        // Things like `self[]` or `$this[]`
        if ($is_generic_array_type
            && self::isSelfTypeString($non_generic_array_type_name)
            && $context->isInClassScope()
        ) {
            // Callers of this method should be checking on their own
            // to see if this type is a reference to 'parent' and
            // dealing with it there. We don't want to have this
            // method be dependent on the code base
            assert(
                'parent' !== $non_generic_array_type_name,
                __METHOD__ . " does not know how to handle the type name 'parent'"
            );

            return GenericArrayType::fromElementType(
                static::fromFullyQualifiedString(
                    (string)$context->getClassFQSEN()
                ), $is_nullable
            );
        }

        // If this is a type referencing the current class
        // in scope such as 'self' or 'static', return that.
        if (self::isSelfTypeString($type_name)
            && $context->isInClassScope()
        ) {
            // Callers of this method should be checking on their own
            // to see if this type is a reference to 'parent' and
            // dealing with it there. We don't want to have this
            // method be dependent on the code base
            assert(
                'parent' !== $type_name,
                __METHOD__ . " does not know how to handle the type name 'parent'"
            );

            return static::fromFullyQualifiedString(
                (string)$context->getClassFQSEN()
            );
        }

        // Merge the current namespace with the given relative
        // namespace
        if (!empty($context->getNamespace()) && !empty($namespace)) {
            $namespace = $context->getNamespace() . '\\' . $namespace;
        } else if (!empty($context->getNamespace())) {
            $namespace = $context->getNamespace();
        } else {
            $namespace = '\\' . $namespace;
        }

        // Attach the context's namespace to the type name
        return self::make(
            $namespace,
            $type_name,
            $template_parameter_type_list,
            $is_nullable,
            $source
        );
    }

    /**
     * @return UnionType
     * A UnionType representing this and only this type
     */
    public function asUnionType() : UnionType
    {
        return new UnionType([$this]);
    }

    /**
     * @return FQSEN
     * A fully-qualified structural element name derived
     * from this type
     */
    public function asFQSEN() : FQSEN
    {
        // Note: some subclasses, such as CallableType, return different subtypes of FQSEN
        return FullyQualifiedClassName::fromType($this);
    }

    /**
     * @return string
     * The name associated with this type
     */
    public function getName() : string
    {
        return $this->name;
    }

    /**
     * @return bool
     * True if this namespace is defined
     */
    public function hasNamespace() : bool
    {
        return !empty($this->namespace);
    }

    /**
     * @return string
     * The namespace associated with this type
     */
    public function getNamespace() : string
    {
        return $this->namespace;
    }

    /**
     *
     */
    public function getIsNullable() : bool
    {
        return $this->is_nullable;
    }

    /**
     * @param bool $is_nullable
     * Set to true if the type should be nullable, else pass
     * false
     *
     * @return Type
     * A new type that is a copy of this type but with the
     * given nullability value.
     */
    public function withIsNullable(bool $is_nullable) : Type
    {
        if ($is_nullable === $this->is_nullable) {
            return $this;
        }
        return static::make(
            $this->getNamespace(),
            $this->getName(),
            $this->getTemplateParameterTypeList(),
            $is_nullable,
            Type::FROM_TYPE
        );
    }

    /**
     * @return bool
     * True if this is a native type (like int, string, etc.)
     *
     */
    public function isNativeType() : bool
    {
        return false;
    }

    /**
     * @return bool
     * True if this is a native type or an array of native types
     * (like int, string, bool[], etc.),
     *
     * @see \Phan\Deprecated\Util::is_native_type
     * Formerly `function is_native_type`
     */
    private static function isInternalTypeString(string $type_name, int $source) : bool
    {
        $type_name = str_replace('[]', '', strtolower($type_name));
        if ($source === Type::FROM_PHPDOC) {
            $type_name = self::canonicalNameFromName($type_name);  // Have to convert boolean[] to bool
        }
        if (!array_key_exists($type_name, self::_internal_type_set)) {
            return false;
        }
        // All values of $type_name exist as a valid phpdoc type, but some don't exist as real types.
        if ($source === Type::FROM_NODE && array_key_exists($type_name, self::_soft_internal_type_set)) {
            return false;
        }
        return true;
    }

    /**
     * @return bool
     * True if this type is a type referencing the
     * class context in which it exists such as 'static'
     * or 'self'.
     */
    public function isSelfType() : bool
    {
        return self::isSelfTypeString((string)$this);
    }

    /**
     * @return bool
     * True if this type is a type referencing the
     * class context 'static'.
     */
    public function isStaticType() : bool
    {
        return 'static' === strtolower(ltrim((string)$this, '\\'));
    }

    /**
     * @param string $type_string
     * A string defining a type such as 'self' or 'int'.
     *
     * @return bool
     * True if the given type references the class context
     * in which it exists such as 'self' or 'parent'
     */
    public static function isSelfTypeString(
        string $type_string
    ) : bool {
        // Note: While 'self' and 'parent' are case insensitive, '$this' is case sensitive
        // Not sure if that should extend to phpdoc.
        return preg_match('/^\\\\?([sS][eE][lL][fF]|[pP][aA][rR][eE][nN][tT]|\\$this)$/', $type_string) > 0;
    }

    /**
     * @param string $type_string
     * A string defining a type such as 'static' or 'int'.
     *
     * @return bool
     * True if the given type references the class context
     * in which it exists is '$this' or 'static'
     */
    public static function isStaticTypeString(
        string $type_string
    ) : bool {
        // Note: While 'self' and 'parent' are case insensitive, '$this' is case sensitive
        // Not sure if that should extend to phpdoc.
        return preg_match('/^\\\\?([sS][tT][aA][tT][iI][cC]|\\$this)$/', $type_string) > 0;
    }

    /**
     * @return bool
     * True if this type is scalar.
     *
     * @see \Phan\Deprecated\Util::type_scalar
     * Formerly `function type_scalar`
     */
    public function isScalar() : bool
    {
        return false;  // Overridden in subclass ScalarType
    }

    /**
     * @return bool
     * True if this type is an object (or the phpdoc `object`)
     */
    public function isObject() : bool
    {
        return true;  // Overridden in various subclasses
    }

    /**
     * @return bool
     * True if this type is iterable.
     */
    public function isIterable() : bool
    {
        return false;  // Overridden in subclass IterableType (with subclass ArrayType)
    }

    /**
     * @return bool
     * True if this type is array-like (is of type array, is
     * a generic array, or implements ArrayAccess).
     */
    public function isArrayLike() : bool
    {
        // includes both nullable and non-nullable ArrayAccess/array/iterable
        return (
            $this->isIterable()
            || $this->isGenericArray()
            || $this->isArrayAccess()
        );
    }

    /**
     * @return bool
     * True if this is a generic type such as 'int[]' or
     * 'string[]'.
     */
    public function isGenericArray() : bool
    {
        return false;  // Overridden in GenericArrayType
    }

    /**
     * @return bool - Returns true if this is \ArrayAccess (nullable or not)
     */
    public function isArrayAccess() : bool
    {
        return (strcasecmp($this->getName(), 'ArrayAccess') === 0
            && $this->getNamespace() === '\\');
    }

    /**
     * @param string $type_name
     * A non-namespaced type name like 'int[]'
     *
     * @return bool
     * True if this is a generic type such as 'int[]' or
     * 'string[]'.
     */
    private static function isGenericArrayString(string $type_name) : bool
    {
        if (strrpos($type_name, '[]') !== false) {
            return $type_name !== '[]';
        }
        return false;
    }

    /**
     * @return Type
     * A variation of this type that is not generic.
     * i.e. 'int[]' becomes 'int'.
     */
    public function genericArrayElementType() : Type
    {
        assert(
            $this->isGenericArray(),
            "Cannot call genericArrayElementType on non-generic array"
        );

        if (($pos = strrpos($this->getName(), '[]')) !== false) {
            assert(
                $this->getName() !== '[]' && $this->getName() !== 'array',
                "Non-generic type requested to be non-generic"
            );

            return Type::make(
                $this->getNamespace(),
                substr($this->getName(), 0, $pos),
                $this->template_parameter_type_list,
                $this->getIsNullable(),
                self::FROM_TYPE
            );
        }

        return $this;
    }

    /**
     * @return Type
     * Get a new type which is the generic array version of
     * this type. For instance, 'int' will produce 'int[]'.
     */
    public function asGenericArrayType() : Type
    {
        if (!($this instanceof GenericArrayType)
            && (
                $this->getName() == 'array'
                || $this->getName() == 'mixed'
            )
        ) {
            return ArrayType::instance(false);
        }

        return GenericArrayType::fromElementType($this, false);
    }

    /**
     * @return bool
     * True if this type has any template parameter types
     */
    public function hasTemplateParameterTypes() : bool
    {
        return !empty($this->template_parameter_type_list);
    }

    /**
     * @return UnionType[]
     * The set of types filling in template parameter types defined
     * on the class specified by this type.
     */
    public function getTemplateParameterTypeList()
    {
        return $this->template_parameter_type_list;
    }

    /**
     * @param CodeBase $code_base
     * The code base to look up classes against
     *
     * @return UnionType[]
     * A map from template type identifier to a concrete type
     */
    public function getTemplateParameterTypeMap(CodeBase $code_base)
    {
        return $this->memoize(__METHOD__, function () use ($code_base) {
            $fqsen = $this->asFQSEN();

            if (!($fqsen instanceof FullyQualifiedClassName)) {
                return [];
            }

            assert($fqsen instanceof FullyQualifiedClassName);

            if (!$code_base->hasClassWithFQSEN($fqsen)) {
                return [];
            }

            $class = $code_base->getClassByFQSEN($fqsen);

            $class_template_type_list = $class->getTemplateTypeMap();

            $template_parameter_type_list =
                $this->getTemplateParameterTypeList();

            $map = [];
            foreach (array_keys($class->getTemplateTypeMap()) as $i => $identifier) {
                if (isset($template_parameter_type_list[$i])) {
                    $map[$identifier] = $template_parameter_type_list[$i];
                }
            }

            return $map;
        });
    }

    /**
     * @param CodeBase
     * The code base to use in order to find super classes, etc.
     *
     * @param $recursion_depth
     * This thing has a tendency to run-away on me. This tracks
     * how bad I messed up by seeing how far the expanded types
     * go
     *
     * @return UnionType
     * Expands class types to all inherited classes returning
     * a superset of this type.
     */
    public function asExpandedTypes(
        CodeBase $code_base,
        int $recursion_depth = 0
    ) : UnionType {
        // We're going to assume that if the type hierarchy
        // is taller than some value we probably messed up
        // and should bail out.
        assert(
            $recursion_depth < 20,
            "Recursion has gotten out of hand"
        );

        if ($this->isNativeType() && !$this->isGenericArray()) {
            return $this->asUnionType();
        }

        $union_type = $this->asUnionType();

        $class_fqsen = $this->isGenericArray()
            ? $this->genericArrayElementType()->asFQSEN()
            : $this->asFQSEN();

        if (!($class_fqsen instanceof FullyQualifiedClassName)) {
            return $union_type;
        }

        assert($class_fqsen instanceof FullyQualifiedClassName);

        if (!$code_base->hasClassWithFQSEN($class_fqsen)) {
            return $union_type;
        }

        $clazz = $code_base->getClassByFQSEN($class_fqsen);

        $union_type->addUnionType(
            $this->isGenericArray()
                ?  $clazz->getUnionType()->asGenericArrayTypes()
                : $clazz->getUnionType()
        );

        // Resurse up the tree to include all types
        $recursive_union_type = new UnionType();
        foreach ($union_type->getTypeSet() as $clazz_type) {
            if ((string)$clazz_type != (string)$this) {
                $recursive_union_type->addUnionType(
                    $clazz_type->asExpandedTypes(
                        $code_base,
                        $recursion_depth + 1
                    )
                );
            } else {
                $recursive_union_type->addType($clazz_type);
            }
        }

        return $recursive_union_type;
    }

    /**
     * @param CodeBase $code_base
     *
     * @param Type $parent
     *
     * @return bool
     * True if this type represents a class which is a sub-type of
     * the class represented by the passed type.
     */
    public function isSubclassOf(CodeBase $code_base, Type $parent) : bool
    {
        $fqsen = $this->asFQSEN();
        assert($fqsen instanceof FullyQualifiedClassName);

        $this_clazz = $code_base->getClassByFQSEN(
            $fqsen
        );

        $parent_fqsen = $parent->asFQSEN();
        assert($parent_fqsen instanceof FullyQualifiedClassName);

        $parent_clazz = $code_base->getClassByFQSEN(
            $parent_fqsen
        );

        return $this_clazz->isSubclassOf($code_base, $parent_clazz);
    }

    /**
     * @return bool
     * True if this Type can be cast to the given Type
     * cleanly
     */
    public function canCastToType(Type $type) : bool
    {
        // Check to see if we have an exact object match
        if ($this === $type) {
            return true;
        }

        if ($type instanceof MixedType) {
            return true;
        }

        // A nullable type cannot cast to a non-nullable type
        if ($this->getIsNullable() && !$type->getIsNullable()) {

            // If this is nullable, but that isn't, and we've
            // configured nulls to cast as anything, ignore
            // the nullable part.
            if (Config::get()->null_casts_as_any_type) {
                return $this->withIsNullable(false)->canCastToType($type);
            }

            return false;
        }

        // Get a non-null version of the type we're comparing
        // against.
        if ($type->getIsNullable()) {
            $type = $type->withIsNullable(false);

            // Check one more time to see if the types are equal
            if ($this === $type) {
                return true;
            }
        }

        // Test to see if we can cast to the non-nullable version
        // of the target type.
        return $this->canCastToNonNullableType($type);
    }

    /**
     * @param Type $type
     * A Type which is not nullable. This constraint is not
     * enforced, so be careful.
     *
     * @return bool
     * True if this Type can be cast to the given Type
     * cleanly
     */
    protected function canCastToNonNullableType(Type $type) : bool
    {
        // can't cast native types (includes iterable or array) to object. ObjectType overrides this function.
        if ($type instanceof ObjectType
            && !$this->isNativeType()
        ) {
            return true;
        }

        if ($type instanceof MixedType) {
            return true;
        }
        // A matrix of allowable type conversions
        static $matrix = [
            '\Traversable' => [
                'iterable' => true,
            ],
            '\Closure' => [
                'callable' => true,
            ],
        ];

        return $matrix[(string)$this][(string)$type] ?? false;
    }

    /**
     * @return string
     * A string representation of this type in FQSEN form.
     */
    public function asFQSENString() : string
    {
        return $this->memoize(__METHOD__, function() {
            if (!$this->hasNamespace()) {
                return $this->getName();
            }

            if ('\\' === $this->getNamespace()) {
                return '\\' . $this->getName();
            }

            return "{$this->getNamespace()}\\{$this->getName()}";
        });
    }

    /**
     * @return string
     * A human readable representation of this type
     * (This is frequently called, so prefer efficient operations)
     */
    public function __toString()
    {
        $string = $this->asFQSENString();

        if (count($this->template_parameter_type_list) > 0) {
            $string .= $this->templateParameterTypeListAsString();
        }

        if ($this->getIsNullable()) {
            $string = '?' . $string;
        }

        return $string;
    }

    /**
     * Gets the part of the Type string for the template parameters.
     * Precondition: $this->template_parameter_string is not null.
     */
    private function templateParameterTypeListAsString() : string {
        return '<' .
            implode(',', array_map(function (UnionType $type) {
                return (string)$type;
            }, $this->template_parameter_type_list)) . '>';
    }

    /**
     * @param string $name
     * Any type name
     *
     * @return string
     * A canonical name for the given type name
     */
    private static function canonicalNameFromName(
        string $name
    ) : string {
        static $map = [
            'boolean'  => 'bool',
            'callback' => 'callable',
            'double'   => 'float',
            'false'    => 'bool',
            'true'     => 'bool',
            'integer'  => 'int',
        ];

        return $map[strtolower($name)] ?? $name;
    }

    /**
     * @param string $type_string
     * Any type string such as 'int' or 'Set<int>'
     *
     * @return Tuple4<string,string,array,bool>
     * A pair with the 0th element being the namespace and the first
     * element being the type name.
     */
    private static function typeStringComponents(
        string $type_string
    ) {
        // Check to see if we have template parameter types
        $template_parameter_type_name_list = [];

        $match = [];
        $is_nullable = false;
        if (preg_match('/' . self::type_regex. '/', $type_string, $match)) {
            $type_string = $match[1];

            // Rip out the nullability indicator if it
            // exists and note its nullability
            $is_nullable = ($match[2] ?? '') == '?';
            if ($is_nullable) {
                $type_string = substr($type_string, 1);
            }

            // If we have a generic array symbol '[]', append that back
            // on to the type string
            if (isset($match[12])) {
                // Figure out the dimensionality of the type array
                $gmatch = [];
                if (preg_match('/\[[\]\[]*\]/', $match[0], $gmatch)) {
                    $type_string .= $gmatch[0];
                }
            }

            $template_parameter_type_name_list = !empty($match[4])
                ?  preg_split('/\s*,\s*/', $match[4])
                : [];
        }

        // Determine if the type name is fully qualified
        // (as specified by a leading backslash).
        $is_fully_qualified = (0 === strpos($type_string, '\\'));

        $fq_class_name_elements =
            array_filter(explode('\\', $type_string));

        $class_name =
            (string)array_pop($fq_class_name_elements);

        $namespace = ($is_fully_qualified ? '\\' : '')
            . implode('\\', array_filter(
                $fq_class_name_elements
            ));

        return new Tuple4(
            $namespace,
            $class_name,
            $template_parameter_type_name_list,
            $is_nullable
        );
    }
}
