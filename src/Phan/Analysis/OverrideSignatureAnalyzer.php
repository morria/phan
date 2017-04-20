<?php declare(strict_types=1);
namespace Phan\Analysis;

use Phan\Analysis\MethodAnalyzer;
use Phan\CodeBase;
use Phan\Issue;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Method;
use Phan\Language\Element\Parameter;
use Phan\Language\Type\IterableType;
use Phan\Language\Type\MixedType;

class OverrideSignatureAnalyzer implements MethodAnalyzer
{
    /**
     * Make sure signatures line up between methods and the
     * methods they override
     *
     * @see https://en.wikipedia.org/wiki/Liskov_substitution_principle
     *
     * @param CodeBase $code_base
     * The code base in which the method exists
     *
     * @param Method $method
     * A method being analyzed
     *
     * @return void
     */
    public function analyzeMethod(
        CodeBase $code_base,
        Method $method
    ) {
        // Hydrate the class this method is coming from in
        // order to understand if its an override or not
        $class = $method->getClass($code_base);
        $class->hydrate($code_base);

        // Check to see if the method is an override
        // $method->analyzeOverride($code_base);

        // Make sure we're actually overriding something
        // TODO(in another PR): check that signatures of magic methods are valid, if not done already (e.g. __get expects one param, most can't define return types, etc.)?
        if (!$method->getIsOverride()) {
            return;
        }

        // Get the method that is being overridden
        $o_method = $method->getOverriddenMethod($code_base);

        // Unless it is an abstract constructor,
        // don't worry about signatures lining up on
        // constructors. We just want to make sure that
        // calling a method on a subclass won't cause
        // a runtime error. We usually know what we're
        // constructing at instantiation time, so there
        // is less of a risk.
        if ($method->getName() == '__construct' && !$o_method->isAbstract()) {
            return;
        }

        // Get the class that the overridden method lives on
        $o_class = $o_method->getClass($code_base);

        // A lot of analyzeOverrideRealSignature is redundant.
        // However, phan should consistently emit both issue types if one of them is suppressed.
        $this->analyzeOverrideRealSignature($code_base, $method, $o_method, $o_class);

        // PHP doesn't complain about signature mismatches
        // with traits, so neither shall we
        // TODO: it does, in some cases, such as a trait existing for an abstract method defined in the class.
        //   (Wrong way around to analyze that, though)
        // It also checks if a trait redefines a method in the class.
        if ($o_class->isTrait()) {
            return;
        }

        // Get the parameters for that method
        $o_parameter_list = $o_method->getParameterList();

        // If we have a parent type defined, map the method's
        // return type and parameter types through it
        $type_option = $class->getParentTypeOption();

        // Map overridden method parameter types through any
        // template type parameters we may have
        if ($type_option->isDefined()) {
            $o_parameter_list =
                array_map(function (Parameter $parameter) use ($type_option, $code_base) : Parameter {

                    if (!$parameter->getUnionType()->hasTemplateType()) {
                        return $parameter;
                    }

                    $mapped_parameter = clone($parameter);

                    $mapped_parameter->setUnionType(
                        $mapped_parameter->getUnionType()->withTemplateParameterTypeMap(
                            $type_option->get()->getTemplateParameterTypeMap(
                                $code_base
                            )
                        )
                    );

                    return $mapped_parameter;
                }, $o_parameter_list);
        }

        // Map overridden method return type through any template
        // type parameters we may have
        $o_return_union_type = $o_method->getUnionType();
        if ($type_option->isDefined()
            && $o_return_union_type->hasTemplateType()
        ) {
            $o_return_union_type =
                $o_return_union_type->withTemplateParameterTypeMap(
                    $type_option->get()->getTemplateParameterTypeMap(
                        $code_base
                    )
                );
        }

        // Determine if the signatures match up
        $signatures_match = true;

        // Make sure the count of parameters matches
        if ($method->getNumberOfRequiredParameters()
            > $o_method->getNumberOfRequiredParameters()
        ) {
            $signatures_match = false;
        } else if ($method->getNumberOfParameters()
            < $o_method->getNumberOfParameters()
        ) {
            $signatures_match = false;

        // If parameter counts match, check their types
        } else {
            foreach ($method->getParameterList() as $i => $parameter) {

                if (!isset($o_parameter_list[$i])) {
                    continue;
                }

                $o_parameter = $o_parameter_list[$i];

                // Changing pass by reference is not ok
                // @see https://3v4l.org/Utuo8
                if ($parameter->isPassByReference() != $o_parameter->isPassByReference()) {
                    $signatures_match = false;
                    break;
                }

                // A stricter type on an overriding method is cool
                // TODO: This doesn't match the definition of LSP.
                // - If you use a stricter param type, you can't call the subclass with args you could call the base class with.
                if ($o_parameter->getUnionType()->isEmpty()
                    || $o_parameter->getUnionType()->isType(MixedType::instance(false))
                ) {
                    continue;
                }

                // TODO: check variadic.

                // Its not OK to have a more relaxed type on an
                // overriding method
                //
                // https://3v4l.org/XTm3P
                if ($parameter->getUnionType()->isEmpty()) {
                    $signatures_match = false;
                    break;
                }

                // If we have types, make sure they line up
                //
                // TODO: should we be expanding the types on $o_parameter
                //       via ->asExpandedTypes($code_base)?
                //
                //       @see https://3v4l.org/ke3kp
                if (!$o_parameter->getUnionType()->canCastToUnionType(
                    $parameter->getUnionType()
                )) {
                    $signatures_match = false;
                    break;
                }
            }
        }

        // Return types should be mappable for LSP
        // Note: PHP requires return types to be identical
        if (!$o_return_union_type->isEmpty()) {

            if (!$method->getUnionType()->asExpandedTypes($code_base)->canCastToUnionType(
                $o_return_union_type
            )) {
                $signatures_match = false;
            }
        }

        // Static or non-static should match
        if ($method->isStatic() != $o_method->isStatic()) {
            if ($o_method->isStatic()) {
                Issue::maybeEmit(
                    $code_base,
                    $method->getContext(),
                    Issue::AccessStaticToNonStatic,
                    $method->getFileRef()->getLineNumberStart(),
                    $o_method->getFQSEN()
                );
            } else {
                Issue::maybeEmit(
                    $code_base,
                    $method->getContext(),
                    Issue::AccessNonStaticToStatic,
                    $method->getFileRef()->getLineNumberStart(),
                    $o_method->getFQSEN()
                );
            }
        }


        if ($o_method->returnsRef() && !$method->returnsRef()) {
            $signatures_match = false;
        }

        if (!$signatures_match) {
            if ($o_method->isPHPInternal()) {
                Issue::maybeEmit(
                    $code_base,
                    $method->getContext(),
                    Issue::ParamSignatureMismatchInternal,
                    $method->getFileRef()->getLineNumberStart(),
                    $method,
                    $o_method
                );
            } else {
                Issue::maybeEmit(
                    $code_base,
                    $method->getContext(),
                    Issue::ParamSignatureMismatch,
                    $method->getFileRef()->getLineNumberStart(),
                    $method,
                    $o_method,
                    $o_method->getFileRef()->getFile(),
                    $o_method->getFileRef()->getLineNumberStart()
                );
            }
        }

        // Access must be compatible
        if ($o_method->isProtected() && $method->isPrivate()
            || $o_method->isPublic() && !$method->isPublic()
        ) {
            if ($o_method->isPHPInternal()) {
                Issue::maybeEmit(
                    $code_base,
                    $method->getContext(),
                    Issue::AccessSignatureMismatchInternal,
                    $method->getFileRef()->getLineNumberStart(),
                    $method,
                    $o_method
                );
            } else {
                Issue::maybeEmit(
                    $code_base,
                    $method->getContext(),
                    Issue::AccessSignatureMismatch,
                    $method->getFileRef()->getLineNumberStart(),
                    $method,
                    $o_method,
                    $o_method->getFileRef()->getFile(),
                    $o_method->getFileRef()->getLineNumberStart()
                );
            }

        }
    }

    /**
     * Previously, Phan bases the analysis off of phpdoc.
     * Keeping that around(e.g. to check that string[] is compatible with string[])
     * and also checking the **real**(non-phpdoc) types.
     *
     * @param $code_base
     * @param $method - The overriding method
     * @param $o_method - The overridden method. E.g. if a subclass overrid a base class implementation, then $o_method would be from the base class.
     * @param $o_class the overridden class
     * @return void
     */
    private function analyzeOverrideRealSignature(
        CodeBase $code_base,
        Method $method,
        Method $o_method,
        Clazz $o_class
    ) {
        if ($o_class->isTrait()) {
            return;  // TODO: properly analyze abstract methods overriding/overridden by traits.
        }

        // Get the parameters for that method
        $o_parameter_list = $o_method->getRealParameterList();

        // Map overridden method return type through any template
        // type parameters we may have
        $o_return_union_type = $o_method->getRealReturnType();

        // Determine if the signatures match up
        $signatures_match = true;

        // Make sure the count of parameters matches
        if ($method->getNumberOfRequiredParameters()
            > $o_method->getNumberOfRequiredParameters()
        ) {
            $signatures_match = false;
        } else if ($method->getNumberOfParameters()
            < $o_method->getNumberOfParameters()
        ) {
            $signatures_match = false;

        // If parameter counts match, check their types
        } else {
            foreach ($method->getRealParameterList() as $i => $parameter) {

                // TODO: check if variadic
                if (!isset($o_parameter_list[$i])) {
                    continue;
                }

                // TODO: check that the variadic types match up?
                $o_parameter = $o_parameter_list[$i];

                // Changing pass by reference is not ok
                // @see https://3v4l.org/Utuo8
                if ($parameter->isPassByReference() != $o_parameter->isPassByReference()) {
                    $signatures_match = false;
                    break;
                }

                // Changing variadic to/from non-variadic is not ok?
                if ($parameter->isVariadic() != $o_parameter->isVariadic()) {
                    $signatures_match = false;
                    break;
                }

                // Either 0 or both of the params must have types for the signatures to be compatible.
                $o_parameter_union_type = $o_parameter->getUnionType();
                $parameter_union_type = $parameter->getUnionType();
                if ($parameter_union_type->isEmpty() != $o_parameter_union_type->isEmpty()) {
                    $signatures_match = false;
                    break;
                }

                // If both have types, make sure they are identical.
                // Non-nullable param types can be substituted with the nullable equivalents.
                // E.g. A::foo(?int $x) can override BaseClass::foo(int $x)
                if (!$parameter_union_type->isEmpty()) {
                    if (!$o_parameter_union_type->isEqualTo($parameter_union_type) &&
                        !($parameter_union_type->containsNullable() && $o_parameter_union_type->isEqualTo($parameter_union_type->nonNullableClone()))
                    ) {
                        // There is one exception to this in php 7.1 - the pseudo-type "iterable" can replace ArrayAccess/array in a subclass
                        // TODO: Traversable and array work, but Iterator doesn't. Check for those specific cases?
                        $is_exception_to_rule = $parameter_union_type->hasIterable() &&
                            $o_parameter_union_type->hasIterable() &&
                            ($parameter_union_type->hasType(IterableType::instance(true)) ||
                             $parameter_union_type->hasType(IterableType::instance(false)) && !$o_parameter_union_type->containsNullable());

                        if (!$is_exception_to_rule) {
                            $signatures_match = false;
                            break;
                        }
                    }
                }
            }
        }

        $return_union_type = $method->getRealReturnType();
        // If the parent has a return type, then return types should be equal.
        // A non-nullable return type can override a nullable return type of the same type.
        if (!$o_return_union_type->isEmpty()) {
            if (!($o_return_union_type->isEqualTo($return_union_type) || (
                $o_return_union_type->containsNullable() && !($o_return_union_type->nonNullableClone()->isEqualTo($return_union_type)))
                )) {
                    $signatures_match = false;
            }
        }

        if ($o_method->returnsRef() && !$method->returnsRef()) {
            $signatures_match = false;
        }

        if (!$signatures_match) {
            if ($o_method->isPHPInternal()) {
                Issue::maybeEmit(
                    $code_base,
                    $method->getContext(),
                    Issue::ParamSignatureRealMismatchInternal,
                    $method->getFileRef()->getLineNumberStart(),
                    $method->toRealSignatureString(),
                    $o_method->toRealSignatureString()
                );
            } else {
                Issue::maybeEmit(
                    $code_base,
                    $method->getContext(),
                    Issue::ParamSignatureRealMismatch,
                    $method->getFileRef()->getLineNumberStart(),
                    $method->toRealSignatureString(),
                    $o_method->toRealSignatureString(),
                    $o_method->getFileRef()->getFile(),
                    $o_method->getFileRef()->getLineNumberStart()
                );
            }
        }
    }
}
