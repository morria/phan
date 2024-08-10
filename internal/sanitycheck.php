#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/internal/lib/IncompatibleSignatureDetectorBase.php';

/**
 * Loads the ReflectionFunction or ReflectionMethod for the given function or method name.
 * Method names must use '::' to separate the class and method names.
 * @throws ReflectionException
 * @phan-file-suppress PhanPluginRemoveDebugAny
 */
function load_internal_function(string $function_name): ReflectionFunctionAbstract
{
    if (strpos($function_name, '::') !== false) {
        [$class_name, $method_name] = explode('::', $function_name, 2);
        $class = new ReflectionClass($class_name);
        return $class->getMethod($method_name);
    } else {
        return new ReflectionFunction($function_name);
    }
}

/**
 * Returns the number of required and optional parameters from Phan's internal signature map entry for some function.
 * as well as whether that map entry has the mistake of putting a required parameter after an optional parameter
 *
 * @param array<int|string,string> $fields - E.g. `['returnType', 'paramNameWithAnnotations'=>'paramType']`
 * @return array{0:int,1:int,2:bool} [int $num_required, int $num_optional, bool $saw_optional_after_required]
 * @throws InvalidArgumentException for invalid fields
 */
function getParametersCountsFromPhan(array $fields): array
{
    $num_required = 0;
    unset($fields[0]);
    $num_optional = count($fields);
    $saw_optional = false;
    $saw_optional_after_required = false;
    foreach ($fields as $type => $_) {
        if (!is_string($type)) {
            throw new InvalidArgumentException("Invalid parameter description $type");
        }

        if (strpos($type, '...') !== false) {
            $num_optional = 10000;
            break;
        } elseif (strpos($type, '=') === false) {
            $num_required++;
            if ($saw_optional) {
                $saw_optional_after_required = true;
            }
        } else {
            $saw_optional = true;
        }
    }
    return [$num_required, $num_optional, $saw_optional_after_required];
}

/**
 * Gets the number of required and actual parameters from reflection.
 *
 * Variadic functions are treated as if they have 10000 optional parameters.
 *
 * @param ReflectionParameter[] $args
 * @return array{0:int,1:int} [$num_required, $num_optional]
 */
function getParameterCountsFromReflection(array $args): array
{
    $num_required = 0;
    $num_optional = count($args);
    foreach ($args as $reflection_parameter) {
        if ($reflection_parameter->isVariadic()) {
            $num_optional = 10000;
            break;
        } elseif ($reflection_parameter->isOptional() || $reflection_parameter->isDefaultValueAvailable()) {
        } else {
            $num_required++;
        }
    }
    return [$num_required, $num_optional];
}

/**
 * @return array<mixed,string> the return type(key 0) and named param types in order
 */
function getPhanSignatureArrayFromReflection(ReflectionFunctionAbstract $function): array
{
    $return_type = $function->getReturnType();
    $return_type_representation = getUnionTypeStringForReflectionType($return_type);
    $result = [0 => $return_type_representation];
    foreach ($function->getParameters() as $reflection_parameter) {
        $param_name = $reflection_parameter->getName();
        if ($reflection_parameter->isVariadic()) {
            $param_name = "...$param_name";
        }
        if ($reflection_parameter->isOptional()) {
            $param_name = "$param_name=";
        }
        if ($reflection_parameter->isPassedByReference()) {
            $param_name = "&$param_name";
        }
        $result[$param_name] = getUnionTypeStringForReflectionType($reflection_parameter->getType());
    }
    return $result;
}

/**
 * This represents an entry in Phan's internal function signatures for a function/method parameter.
 *
 * (e.g. `'...args' => 'string|int'`)
 */
class PhanParameterInfo
{
    /** @var string the parameter name */
    public $name;

    /**
     * @var string the original spec string, e.g. `'...args'`
     * @suppress PhanWriteOnlyPublicProperty may use this in the future, e.g. for warnings
     */
    public $original_name_spec;

    /** @var bool is this parameter optional */
    public $is_optional;

    /** @var bool is this parameter passed by reference */
    public $is_by_reference;

    /** @var bool is this parameter variadic */
    public $is_variadic;

    /** @var string the union type string */
    public $value;

    public function __construct(string $original_name_spec, string $value_spec)
    {
        $this->original_name_spec = $original_name_spec;
        $name = $original_name_spec;
        $this->is_by_reference = ($name[0] ?? '') === '&';
        $name = ltrim($name, '&');
        $this->is_variadic = strpos($name, '...') !== false;
        $name = trim($name, '.');
        $this->is_optional = $name[strlen($name) - 1] === '=';
        $name = rtrim($name, '=');
        $this->value = $value_spec;
        $this->name = $name;
    }
}

/**
 * Extracts a list of representations of parameters from the compact array description ($fields) from FunctionSignatureMap.php
 *
 * @param string[] $fields
 * @phan-param array{0:string}|array<string,string> $fields
 * @return list<PhanParameterInfo>
 */
function get_parameters_from_phan($fields): array
{
    unset($fields[0]);
    $result = [];
    foreach ($fields as $original_name => $value) {
        $result[] = new PhanParameterInfo($original_name, $value);
    }
    return $result;
}

/**
 * Given a reflection type, return Phan's union type string for that type
 */
function getUnionTypeStringForReflectionType(?ReflectionType $reflection_type = null): string
{
    if (!$reflection_type) {
        return '';
    }
    $reflection_representation = (string)$reflection_type;
    if ($reflection_type->allowsNull()) {
        $reflection_representation = "?$reflection_representation";
    }
    return $reflection_representation;
}

/**
 * Check if Phan's function signature map has any contradictions with PHP's function signature map.
 *
 * This may either be a bug in Phan's signature map or in the reflection info.
 *
 * Note that certain bugs in the reflection info may be backwards incompatible changes
 * (e.g. making an optional method parameter required, changing real param/return types in certain ways, etc.
 * may be backwards incompatible for classes that subclass a class,
 * and would have to wait for the next PHP major version (8.0))
 *
 * @param array<int|string,string> $fields the signature from Phan being checked for contradictions
 * @param array<string,array<int|string,string>> $signatures the set of all signatures (to check for existence of alternates)
 * @throws InvalidArgumentException for invalid return types
 */
function check_fields(string $function_name, array $fields, array $signatures): void
{
    $return_type = $fields[0];  // TODO: Check type
    if (!is_string($return_type)) {
        throw new InvalidArgumentException("Invalid return type: " . json_encode($return_type));
    }

    $original_function_name = $function_name;
    $function_name = preg_replace("/'\\d+$/D", "", $function_name);
    // echo $function_name . "\n";
    try {
        $function = load_internal_function($function_name);
    } catch (ReflectionException $_) {
        return;
    }
    $real_return_type = (string)$function->getReturnType();
    // TODO: Account for alternate signatures, check for $function_name . "\1"
    $has_alternate = isset($signatures[$function_name . "'1"]);
    if ($real_return_type !== '' && strtolower(ltrim($real_return_type)) !== strtolower($return_type)) {
        if ($has_alternate) {
            echo "(Has alternate): ";
        }
        echo "Found mismatch for $function_name: Reflection says return type is '$real_return_type', Phan says return type is '$return_type'\n";
    }

    /**
     * Shorter wrapper name.
     * @param array<mixed,string> $fields
     */
    $encode_signature = static function (array $fields): string {
        return IncompatibleSignatureDetectorBase::encodeSignatureArguments($fields);
    };


    $reflection_parameters = $function->getParameters();
    [$phan_required_count, $phan_optional_count, $saw_optional_after_required] = getParametersCountsFromPhan($fields);
    [$php_computed_required_count, $php_optional_count] = getParameterCountsFromReflection($reflection_parameters);
    $generate_comparison_text = static function () use ($encode_signature, $fields, $function): string {
        $php_fields = getPhanSignatureArrayFromReflection($function);
        return sprintf("%s vs PHP's %s", $encode_signature($fields), $encode_signature($php_fields));
    };
    $php_required_count = $function->getNumberOfRequiredParameters();
    if ($saw_optional_after_required) {
        echo "Saw optional after required for $original_function_name: " . $encode_signature($fields) . "\n";
    }
    if ($php_computed_required_count !== $php_required_count) {
        if ($has_alternate) {
            echo "(Has alternate): ";
        }
        echo "Found mismatch for $original_function_name: Computed required count ($php_computed_required_count) !== getNumberOfRequiredParameters ($php_required_count)\n";
    }

    if ($php_required_count < $phan_required_count) {
        if ($has_alternate) {
            echo "(Has alternate): ";
        }
        printf(
            "Found mismatch for %s: PHP has fewer required parameters (%d) than phan does (%d): %s\n",
            $original_function_name,
            $php_required_count,
            $phan_required_count,
            $generate_comparison_text()
        );
    }

    if ($php_optional_count > $phan_optional_count) {
        if ($has_alternate) {
            echo "(Has alternate): ";
        }
        printf(
            "Found mismatch for %s: PHP has more optional parameters (%d) than phan does (%d): %s\n",
            $original_function_name,
            $php_optional_count,
            $phan_optional_count,
            $generate_comparison_text()
        );
    }

    $phan_parameters = get_parameters_from_phan($fields);
    foreach ($reflection_parameters as $i => $reflection_parameter) {
        $phan_parameter = $phan_parameters[$i] ?? null;
        if ($phan_parameter instanceof PhanParameterInfo) {
            $reflection_is_by_reference = $reflection_parameter->isPassedByReference();
            if ($phan_parameter->is_by_reference !== $reflection_is_by_reference) {
                if ($has_alternate) {
                    echo "(Has alternate): ";
                }
                if ($reflection_is_by_reference) {
                    // TODO: Switch to printf
                    printf(
                        "Found mismatch for %s param %s and phan param %s: PHP says param is by reference, but phan doesn't: %s\n",
                        $original_function_name,
                        $reflection_parameter->getName(),
                        $phan_parameter->name,
                        $generate_comparison_text()
                    );
                } else {
                    printf(
                        "Found mismatch for %s param %s and phan param %s: PHP says param is not by reference, but phan does: %s\n",
                        $original_function_name,
                        $reflection_parameter->getName(),
                        $phan_parameter->name,
                        $generate_comparison_text()
                    );
                }
            }
            $reflection_is_variadic = $reflection_parameter->isVariadic();
            if ($phan_parameter->is_variadic !== $reflection_is_variadic) {
                if ($has_alternate) {
                    echo "(Has alternate): ";
                }
                if ($reflection_is_variadic) {
                    printf(
                        "Found mismatch for %s param %s and phan param %s: PHP says param is variadic, but phan doesn't: %s\n",
                        $original_function_name,
                        $reflection_parameter->getName(),
                        $phan_parameter->name,
                        $generate_comparison_text()
                    );
                } else {
                    printf(
                        "Found mismatch for %s param %s and phan param %s: PHP says param is not variadic, but phan does: %s\n",
                        $original_function_name,
                        $reflection_parameter->getName(),
                        $phan_parameter->name,
                        $generate_comparison_text()
                    );
                }
            }

            $reflection_is_optional = $reflection_parameter->isOptional() || $reflection_is_variadic;
            if ($phan_parameter->is_optional !== $reflection_is_optional) {
                if ($has_alternate) {
                    echo "(Has alternate): ";
                }
                if ($reflection_is_optional) {
                    printf(
                        "Found mismatch for %s param %s and phan param %s: PHP says param is optional, but phan doesn't: %s\n",
                        $original_function_name,
                        $reflection_parameter->getName(),
                        $phan_parameter->name,
                        $generate_comparison_text()
                    );
                } else {
                    printf(
                        "Found mismatch for %s param %s and phan param %s: PHP says param is not optional, but phan does: %s\n",
                        $original_function_name,
                        $reflection_parameter->getName(),
                        $phan_parameter->name,
                        $generate_comparison_text()
                    );
                }
            }

            if ($reflection_parameter->hasType()) {
                $reflection_type = $reflection_parameter->getType();
                if ($reflection_type === null) {
                    echo "Null reflection_type for $original_function_name should not happen\n";
                    continue;
                }
                $reflection_representation = getUnionTypeStringForReflectionType($reflection_type);
                $phan_representation = $phan_parameter->value;
                if (strcasecmp($reflection_representation, $phan_representation) !== 0) {
                    if ($reflection_representation === 'array' && strpos($phan_representation, '[]') !== false) {
                        // nothing to do
                    } else {
                        if ($has_alternate) {
                            echo "(Has alternate): ";
                        }
                        printf(
                            "Found mismatch for %s param %s and phan param %s: PHP says param is type '%s', but phan says '%s': %s\n",
                            $original_function_name,
                            $reflection_parameter->getName(),
                            $phan_parameter->name,
                            $reflection_representation,
                            $phan_representation,
                            $generate_comparison_text()
                        );
                    }
                }
            }
        }
    }
}

/**
 * Load Phan's function signatures and check that they are compatible with Reflection's real function/method signatures
 */
function main_check_fields(): void
{
    error_reporting(E_ALL);

    $signatures = require __DIR__ . '/../src/Phan/Language/Internal/FunctionSignatureMap.php';
    foreach ($signatures as $function_name => $fields) {
        check_fields($function_name, $fields, $signatures);
    }
    echo "Done sanity checks of function and method signatures\n";
}
main_check_fields();

/**
DOMDocument::createProcessingInstruction has wrong reflection, it is optional
DOMDocument::importNode has wrong reflection, it is optional
DOMImplementation::createDocument
DOMImplementation::createDocumentType
DOMNamedNodeMap::getNamedItemNS should say mandatory?
DOMNode::C14NFile should say optional
Incorrect reflectionfunction for fsockopen, port is optional
Incorrect reflectionfunction for getenv, returns array if 0 parameters are provided
get_resources has incorrect reflection info
gzgetss has incorrect reflection info
imageaffinematrixget should be optional? Not sure
 */
