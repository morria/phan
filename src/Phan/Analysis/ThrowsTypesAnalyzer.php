<?php declare(strict_types=1);
namespace Phan\Analysis;

use Phan\Suggestion;
use Phan\CodeBase;
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

class ThrowsTypesAnalyzer
{

    /**
     * Check method phpdoc (at)throws types to make sure they're valid
     *
     * @return void
     */
    public static function analyzeThrowsTypes(
        CodeBase $code_base,
        FunctionInterface $method
    ) {
        foreach ($method->getThrowsUnionType()->getTypeSet() as $type) {
            self::analyzeSingleThrowType($code_base, $method, $type);
        }
    }

    /**
     * Check a throw type to make sure it's valid
     *
     * @return void
     */
    private static function analyzeSingleThrowType(
        CodeBase $code_base,
        FunctionInterface $method,
        Type $type
    ) {
        if (!$type->isObject()) {
            Issue::maybeEmit(
                $code_base,
                $method->getContext(),
                Issue::TypeInvalidThrowsNonObject,
                $method->getFileRef()->getLineNumberStart(),
                $method->getName(),
                (string)$type
            );
            return;
        }
        if ($type instanceof TemplateType) {
            // TODO: Add unit tests of templates for return types and checks
            if ($method instanceof Method && $method->isStatic()) {
                Issue::maybeEmit(
                    $code_base,
                    $method->getContext(),
                    Issue::TemplateTypeStaticMethod,
                    $method->getFileRef()->getLineNumberStart(),
                    (string)$method->getFQSEN()
                );
            }
            return;
        }
        if ($type instanceof ObjectType) {
            // (at)throws object is valid
            return;
        }
        static $throwable;
        if ($throwable === null) {
            $throwable = Type::fromFullyQualifiedString('\\Throwable');
        }
        if ($type === $throwable) {
            // allow (at)throws Throwable.
            return;
        }

        $type_fqsen = $type->asFQSEN();
        \assert($type_fqsen instanceof FullyQualifiedClassName, 'non-native types must be class names');
        if (!$code_base->hasClassWithFQSEN($type_fqsen)) {
            Issue::maybeEmitWithParameters(
                $code_base,
                $method->getContext(),
                Issue::UndeclaredTypeThrowsType,
                $method->getFileRef()->getLineNumberStart(),
                [$method->getName(), $type],
                self::suggestSimilarClassForThrownClass($code_base, $method->getContext(), $type_fqsen)
            );
            return;
        }
        $exception_class = $code_base->getClassByFQSEN($type_fqsen);
        if ($exception_class->isTrait() || $exception_class->isInterface()) {
            Issue::maybeEmitWithParameters(
                $code_base,
                $method->getContext(),
                $exception_class->isTrait() ? Issue::TypeInvalidThrowsIsTrait : Issue::TypeInvalidThrowsIsInterface,
                $method->getFileRef()->getLineNumberStart(),
                [$method->getName(), $type],
                self::suggestSimilarClassForThrownClass($code_base, $method->getContext(), $type_fqsen)
            );
            return;
        }

        if (!($type->asExpandedTypes($code_base)->hasType($throwable))) {
            Issue::maybeEmitWithParameters(
                $code_base,
                $method->getContext(),
                Issue::TypeInvalidThrowsNonThrowable,
                $method->getFileRef()->getLineNumberStart(),
                [$method->getName(), $type],
                self::suggestSimilarClassForThrownClass($code_base, $method->getContext(), $type_fqsen)
            );
            return;
        }
    }

    /**
     * @return ?Suggestion
     */
    protected static function suggestSimilarClassForThrownClass(
        CodeBase $code_base,
        Context $context,
        FullyQualifiedClassName $type_fqsen
    ) {
        return IssueFixSuggester::suggestSimilarClass(
            $code_base,
            $context,
            $type_fqsen,
            IssueFixSuggester::createFQSENFilterFromClassFilter($code_base, function (Clazz $class) use ($code_base) : bool {
                if ($class->isTrait()) {
                    return false;
                }
                return $class->getFQSEN()->asType()->asExpandedTypes($code_base)->hasType(Type::fromFullyQualifiedString('\Throwable'));
            })
        );
    }
}
