<?php

declare(strict_types=1);

namespace Phan\Analysis;

use Phan\CodeBase;
use Phan\Exception\IssueException;
use Phan\Issue;
use Phan\IssueFixSuggester;
use Phan\Language\Element\Clazz;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\Type;
use Phan\Language\Type\TemplateType;
use Phan\Language\UnionType;

/**
 * An analyzer that checks a class's properties for issues.
 */
class ClassConstantTypesAnalyzer
{

    /**
     * Check to see if the given class's properties have issues.
     */
    public static function analyzeClassConstantTypes(CodeBase $code_base, Clazz $clazz): void
    {
        foreach ($clazz->getConstantMap($code_base) as $constant) {
            // This phase is done before the analysis phase, so there aren't any dynamic properties to filter out.

            // Get the union type of this constant. This may throw (e.g. it can refer to missing elements).
            $comment = $constant->getComment();
            if (!$comment) {
                continue;
            }
            foreach ($comment->getVariableList() as $variable_comment) {
                try {
                    $union_type = $variable_comment->getUnionType();
                } catch (IssueException $exception) {
                    Issue::maybeEmitInstance(
                        $code_base,
                        $constant->getContext(),
                        $exception->getIssueInstance()
                    );
                    continue;
                }

                if ($union_type->hasTemplateTypeRecursive()) {
                    Issue::maybeEmit(
                        $code_base,
                        $constant->getContext(),
                        Issue::TemplateTypeConstant,
                        $constant->getFileRef()->getLineNumberStart(),
                        $constant->getFQSEN()
                    );
                }
                // Look at each type in the parameter's Union Type
                foreach ($union_type->withFlattenedArrayShapeOrLiteralTypeInstances()->getTypeSet() as $outer_type) {
                    $has_object = $outer_type->isObject() && ! self::typeIsEnum( $code_base, $outer_type );
                    foreach ($outer_type->getReferencedClasses() as $type) {
                        $has_object = ! self::typeIsEnum( $code_base, $type );
                        // If it's a reference to self, it's OK
                        if ($type->isSelfType()) {
                            continue;
                        }

                        if (!($constant->hasDefiningFQSEN() && $constant->getDefiningFQSEN() === $constant->getFQSEN())) {
                            continue;
                        }
                        if ($type instanceof TemplateType) {
                            continue;
                        }

                        // Make sure the class exists
                        $type_fqsen = FullyQualifiedClassName::fromType($type);

                        if ($code_base->hasClassWithFQSEN($type_fqsen)) {
                            if ($code_base->hasClassWithFQSEN($type_fqsen->withAlternateId(1))) {
                                UnionType::emitRedefinedClassReferenceWarning(
                                    $code_base,
                                    $constant->getContext(),
                                    $type_fqsen
                                );
                            }
                        } else {
                            Issue::maybeEmitWithParameters(
                                $code_base,
                                $constant->getContext(),
                                Issue::UndeclaredTypeClassConstant,
                                $constant->getFileRef()->getLineNumberStart(),
                                [$constant->getFQSEN(), (string)$outer_type],
                                IssueFixSuggester::suggestSimilarClass($code_base, $constant->getContext(), $type_fqsen, null, 'Did you mean', IssueFixSuggester::CLASS_SUGGEST_CLASSES_AND_TYPES)
                            );
                        }
                    }
                    if ($has_object) {
                        Issue::maybeEmitWithParameters(
                            $code_base,
                            $constant->getContext(),
                            Issue::CommentObjectInClassConstantType,
                            $constant->getFileRef()->getLineNumberStart(),
                            [$constant->getFQSEN(), (string)$outer_type]
                        );
                    }
                }
            }
        }
    }


    private static function typeIsEnum( CodeBase $code_base, Type $type ) : bool {
        if ( ! $type->isObject() ) {
            return false;
        }
        $class_fqsen = $type->asFQSEN();
        if ( $class_fqsen instanceof FullyQualifiedClassName
            && $code_base->hasClassWithFQSEN($class_fqsen)) {
            $clazz = $code_base->getClassByFQSEN($class_fqsen);
            if ( $clazz->isEnum() ) {
                return true;
            }
        }
        return false;
    }
}
