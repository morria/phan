<?php

declare(strict_types=1);

namespace Phan\Analysis;

use Phan\CodeBase;
use Phan\Config;
use Phan\Exception\CodeBaseException;
use Phan\Exception\RecursionDepthException;
use Phan\Issue;
use Phan\IssueFixSuggester;
use Phan\Language\Context;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Element\Method;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\Type;
use Phan\Language\Type\ObjectType;
use Phan\Language\Type\TemplateType;
use Phan\Suggestion;

/**
 * An analyzer that checks method phpdoc (at)throws types of function-likes to make sure they're valid
 */
class ThrowsTypesAnalyzer
{

    /**
     * Check phpdoc (at)throws types of function-likes to make sure they're valid
     */
    public static function analyzeThrowsTypes(
        CodeBase $code_base,
        FunctionInterface $method
    ): void {
        try {
            self::analyzeThrowsTypesInner($code_base, $method);
        } catch (RecursionDepthException $_) {
        }
    }

    private static function analyzeThrowsTypesInner(
        CodeBase $code_base,
        FunctionInterface $method
    ): void {
        foreach ($method->getOwnThrowsUnionType()->getTypeSet() as $type) {
            // TODO: When analyzing the method body, only check the valid exceptions
            self::analyzeSingleThrowType($code_base, $method, $type);
        }

        if ($method instanceof Method) {
            self::maybeInheritPHPDocThrowsTypes($code_base, $method);
        }
    }

    /**
     * Check a throw type to make sure it's valid
     *
     * @return bool - True if the type can be thrown
     */
    private static function analyzeSingleThrowType(
        CodeBase $code_base,
        FunctionInterface $method,
        Type $type
    ): bool {
        /**
         * @param list<int|string|Type> $args
         */
        $maybe_emit_for_method = static function (string $issue_type, array $args, ?Suggestion $suggestion = null) use ($code_base, $method): void {
            Issue::maybeEmitWithParameters(
                $code_base,
                $method->getContext(),
                $issue_type,
                $method->getContext()->getLineNumberStart(),
                $args,
                $suggestion
            );
        };
        if (!$type->isObject()) {
            $maybe_emit_for_method(
                Issue::TypeInvalidThrowsNonObject,
                [$method->getName(), (string)$type]
            );
            return false;
        }
        if ($type instanceof TemplateType) {
            // TODO: Add unit tests of templates for return types and checks.
            // E.g. should warn if passing in something that can't cast to throwable
            if ($method instanceof Method && $method->isStatic() && !$method->declaresTemplateTypeInComment($type)) {
                $maybe_emit_for_method(
                    Issue::TemplateTypeStaticMethod,
                    [(string)$method->getFQSEN()]
                );
            }
            return false;
        }
        if ($type instanceof ObjectType) {
            // (at)throws object is valid and should be treated like Throwable
            // NOTE: catch (object $o) does nothing in php 7.2.
            return true;
        }
        static $throwable;
        if ($throwable === null) {
            $throwable = Type::throwableInstance();
        }
        if ($type === $throwable) {
            // allow (at)throws Throwable.
            return true;
        }
        $type = $type->withStaticResolvedInContext($method->getContext());

        $type_fqsen = FullyQualifiedClassName::fromType($type);
        if (!$code_base->hasClassWithFQSEN($type_fqsen)) {
            $maybe_emit_for_method(
                Issue::UndeclaredTypeThrowsType,
                [$method->getName(), $type],
                self::suggestSimilarClassForThrownClass($code_base, $method->getContext(), $type_fqsen)
            );
            return false;
        }
        $exception_class = $code_base->getClassByFQSEN($type_fqsen);
        if ($exception_class->isTrait() || $exception_class->isInterface()) {
            $maybe_emit_for_method(
                $exception_class->isTrait() ? Issue::TypeInvalidThrowsIsTrait : Issue::TypeInvalidThrowsIsInterface,
                [$method->getName(), $type]
            );
            return $exception_class->isInterface();
        }

        if (!($type->asExpandedTypes($code_base)->hasType($throwable))) {
            $maybe_emit_for_method(
                Issue::TypeInvalidThrowsNonThrowable,
                [$method->getName(), $type],
                self::suggestSimilarClassForThrownClass($code_base, $method->getContext(), $type_fqsen)
            );
        }
        return true;
    }

    protected static function suggestSimilarClassForThrownClass(
        CodeBase $code_base,
        Context $context,
        FullyQualifiedClassName $type_fqsen
    ): ?Suggestion {
        return IssueFixSuggester::suggestSimilarClass(
            $code_base,
            $context,
            $type_fqsen,
            IssueFixSuggester::createFQSENFilterFromClassFilter($code_base, static function (Clazz $class) use ($code_base): bool {
                if ($class->isTrait()) {
                    return false;
                }
                return $class->getFQSEN()->asType()->asExpandedTypes($code_base)->hasType(Type::throwableInstance());
            })
        );
    }

    private static function maybeInheritPHPDocThrowsTypes(
        CodeBase $code_base,
        Method $method
    ): void {
        if (!Config::getValue('inherit_phpdoc_types')) {
            return;
        }

        try {
            $overridden_method_list = $method->getOverriddenMethods($code_base);
        } catch (CodeBaseException $_) {
            return;
        }

        foreach ($overridden_method_list as $overridden_method) {
            self::inheritPHPDocThrowsTypes($method, $overridden_method);
        }
    }

    /**
     * Inherit phpdoc types for (at)throws of $method from $overridden_method.
     * This is the default behavior, see https://www.phpdoc.org/docs/latest/guides/inheritance.html
     */
    private static function inheritPHPDocThrowsTypes(
        Method $method,
        Method $overridden_method
    ): void {
        // The method was already from phpdoc.
        if ($method->isFromPHPDoc()) {
            return;
        }

        $parent_throws_type = $overridden_method->getFullThrowsUnionType();
        if (!$parent_throws_type->isEmpty()) {
            $method->setInheritedThrowsUnionType($parent_throws_type);
        }
    }
}
