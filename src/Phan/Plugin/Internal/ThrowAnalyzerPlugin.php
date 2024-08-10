<?php

declare(strict_types=1);

namespace Phan\Plugin\Internal;

use AssertionError;
use ast;
use ast\Node;
use Phan\AST\ASTReverter;
use Phan\AST\ContextNode;
use Phan\AST\UnionTypeVisitor;
use Phan\CodeBase;
use Phan\Config;
use Phan\Exception\CodeBaseException;
use Phan\Exception\IssueException;
use Phan\Exception\NodeException;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Element\Method;
use Phan\Language\Type;
use Phan\Language\UnionType;
use Phan\PluginV3;
use Phan\PluginV3\AnalyzeMethodCapability;
use Phan\PluginV3\PluginAwarePostAnalysisVisitor;
use Phan\PluginV3\PostAnalyzeNodeCapability;

/**
 * Analyzes throw statements and compares them against the phpdoc (at)throws annotations
 */
class ThrowAnalyzerPlugin extends PluginV3 implements PostAnalyzeNodeCapability, AnalyzeMethodCapability
{
    /**
     * This is invalidated every time this plugin is loaded (e.g. for tests)
     * @var ?UnionType
     */
    public static $configured_ignore_throws_union_type = null;

    public static function getPostAnalyzeNodeVisitorClassName(): string
    {
        self::$configured_ignore_throws_union_type = null;
        if (Config::getValue('warn_about_undocumented_exceptions_thrown_by_invoked_functions')) {
            return ThrowRecursiveVisitor::class;
        }
        return ThrowVisitor::class;
    }

    /**
     * Check for throw statements in __toString()
     *
     * @param CodeBase $code_base
     * The code base in which the method exists
     *
     * @param Method $method
     * A method being analyzed
     *
     * @override
     */
    public function analyzeMethod(
        CodeBase $code_base,
        Method $method
    ): void {
        if (Config::get_closest_target_php_version_id() >= 70400) {
            return;
        }
        if (\strcasecmp($method->getName(), '__toString') !== 0) {
            return;
        }
        $throws_union_type = $method->getOwnThrowsUnionType();
        if ($throws_union_type->isEmpty()) {
            return;
        }
        Issue::maybeEmit(
            $code_base,
            $method->getContext(),
            Issue::ThrowCommentInToString,
            $method->getContext()->getLineNumberStart(),
            $method->getRepresentationForIssue(),
            $throws_union_type
        );
    }
}

/**
 * Visits throw statements to compares them against the phpdoc (at)throws annotations in the function-like scope
 */
class ThrowVisitor extends PluginAwarePostAnalysisVisitor
{
    /**
     * @var list<Node> Dynamic
     * @suppress PhanReadOnlyProtectedProperty set by the framework
     */
    protected $parent_node_list;

    /**
     * @override
     */
    public function visitThrow(Node $node): void
    {
        $code_base = $this->code_base;
        $context = $this->context;
        $union_type = UnionTypeVisitor::unionTypeFromNode($code_base, $context, $node->children['expr']);
        $union_type = $this->withoutCaughtUnionTypes($union_type, true);
        if ($union_type->isEmpty()) {
            // Give up if we don't know
            return;
        }
        if (!$context->isInFunctionLikeScope()) {
            $this->warnAboutPossiblyThrownTypeIgnoringFunctionThrowsComment($node, $union_type, null);
            return;
        }

        $analyzed_function = $context->getFunctionLikeInScope($code_base);
        if (!Config::getValue('warn_about_undocumented_throw_statements')) {
            $this->warnAboutPossiblyThrownTypeIgnoringFunctionThrowsComment($node, $union_type, $analyzed_function);
            return;
        }

        if (Config::get_closest_target_php_version_id() < 70400) {
            if ($analyzed_function instanceof Method && \strcasecmp('__toString', $analyzed_function->getName()) === 0) {
                $this->emitIssue(
                    Issue::ThrowStatementInToString,
                    $node->lineno,
                    $analyzed_function->getRepresentationForIssue(),
                    (string)$union_type
                );
            }
        }

        // TODO: This seems like it didn't work for A::c(A::d()) - See #1960 (InvalidArgumentException wasn't detected)
        foreach ($this->parent_node_list as $parent) {
            if ($parent->kind !== ast\AST_TRY) {
                continue;
            }
            foreach ($parent->children['catches']->children as $catch_node) {
                if (!$catch_node instanceof Node) {
                    throw new AssertionError('Expected Node for catch statement');
                }
                // @phan-suppress-next-line PhanThrowTypeAbsentForCall hopefully impossible to see for this AST
                $caught_union_type = UnionTypeVisitor::unionTypeFromClassNode($code_base, $context, $catch_node->children['class']);
                foreach ($union_type->getTypeSet() as $type) {
                    if (!$type->asPHPDocUnionType()->canCastToUnionType($caught_union_type, $code_base)) {
                        $union_type = $union_type->withoutType($type);
                        if ($union_type->isEmpty()) {
                            return;
                        }
                    }
                }
            }
        }
        $this->warnAboutPossiblyThrownType($node, $analyzed_function, $union_type);
    }

    protected function withoutCaughtUnionTypes(UnionType $union_type, bool $is_raw_throw): UnionType
    {
        if ($union_type->isEmpty()) {
            if (!$is_raw_throw) {
                return $union_type;
            }
            // Infer Throwable, if the original $union_type was empty
            // and there are no try/catch blocks wrapping this throw statement.
            foreach ($this->parent_node_list as $parent) {
                if ($parent->kind === ast\AST_TRY) {
                    return $union_type;
                }
            }
            return UnionType::fromFullyQualifiedRealString('\Throwable');
        }

        foreach ($this->parent_node_list as $parent) {
            if ($parent->kind !== ast\AST_TRY) {
                continue;
            }
            foreach ($parent->children['catches']->children as $catch_node) {
                if (!$catch_node instanceof Node) {
                    throw new AssertionError("Impossible, expected Node for catch statement");
                }
                // @phan-suppress-next-line PhanThrowTypeAbsentForCall hopefully impossible to see for this AST
                $caught_union_type = UnionTypeVisitor::unionTypeFromClassNode($this->code_base, $this->context, $catch_node->children['class']);
                foreach ($union_type->getTypeSet() as $type) {
                    if ($type->asPHPDocUnionType()->canCastToUnionType($caught_union_type, $this->code_base)) {
                        $union_type = $union_type->withoutType($type);
                        if ($union_type->isEmpty()) {
                            return $union_type;
                        }
                    }
                }
            }
        }
        return $union_type;
    }

    /**
     * @param Node $node a node of kind ast\AST_THROW
     */
    protected function warnAboutPossiblyThrownType(
        Node $node,
        FunctionInterface $analyzed_function,
        UnionType $union_type,
        ?FunctionInterface $call = null
    ): void {
        if ($union_type->isEmpty()) {
            return;
        }
        if (!$union_type->canCastToDeclaredType($this->code_base, $this->context, UnionType::fromFullyQualifiedRealString('\Throwable'))) {
            $this->emitIssue(
                Issue::TypeInvalidThrowStatementNonThrowable,
                $node->lineno,
                $analyzed_function->getRepresentationForIssue(),
                ASTReverter::toShortString($node->children['expr']),
                (string)$union_type,
                '\Throwable'
            );
        }
        foreach ($union_type->getTypeSet() as $type) {
            if (!$this->shouldWarnAboutThrowType($type)) {
                continue;
            }
            if ($type->hasTemplateTypeRecursive()) {
                continue;
            }
            $throws_union_type = $analyzed_function->getFullThrowsUnionType();
            if ($throws_union_type->isEmpty()) {
                if ($call !== null) {
                    $this->emitIssue(
                        Issue::ThrowTypeAbsentForCall,
                        $node->lineno,
                        $analyzed_function->getRepresentationForIssue(),
                        (string)$union_type,
                        $call->getRepresentationForIssue()
                    );
                } else {
                    $this->emitIssue(
                        Issue::ThrowTypeAbsent,
                        $node->lineno,
                        $analyzed_function->getRepresentationForIssue(),
                        ASTReverter::toShortString($node->children['expr']),
                        (string)$union_type
                    );
                }
                continue;
            }
            if (!$type->asPHPDocUnionType()->canCastToUnionType($throws_union_type, $this->code_base)) {
                if ($call !== null) {
                    $this->emitIssue(
                        Issue::ThrowTypeMismatchForCall,
                        $node->lineno,
                        $analyzed_function->getRepresentationForIssue(),
                        (string)$union_type,
                        $call->getRepresentationForIssue(),
                        $throws_union_type
                    );
                } else {
                    $this->emitIssue(
                        Issue::ThrowTypeMismatch,
                        $node->lineno,
                        $analyzed_function->getRepresentationForIssue(),
                        ASTReverter::toShortString($node->children['expr']),
                        (string)$union_type,
                        $throws_union_type
                    );
                }
            }
        }
    }

    /**
     * @param Node $node a node of kind ast\AST_THROW
     */
    protected function warnAboutPossiblyThrownTypeIgnoringFunctionThrowsComment(
        Node $node,
        UnionType $union_type,
        ?FunctionInterface $analyzed_function
    ): void {
        if ($union_type->isEmpty()) {
            return;
        }
        $throwable = UnionType::fromFullyQualifiedRealString('\Throwable');
        if (!$union_type->canCastToDeclaredType($this->code_base, $this->context, $throwable)) {
            $this->emitIssue(
                Issue::TypeInvalidThrowStatementNonThrowable,
                $node->lineno,
                $analyzed_function ? $analyzed_function->getRepresentationForIssue() : '(top level statement)',
                ASTReverter::toShortString($node->children['expr']),
                (string)$union_type,
                '\Throwable'
            );
        }
    }

    protected static function calculateConfiguredIgnoreThrowsUnionType(): UnionType
    {
        $throws_union_type = UnionType::empty();
        foreach (Config::getValue('exception_classes_with_optional_throws_phpdoc') as $type_string) {
            if (!\is_string($type_string) || $type_string === '') {
                continue;
            }
            $throws_union_type = $throws_union_type->withUnionType(UnionType::fromStringInContext($type_string, new Context(), Type::FROM_PHPDOC));
        }
        return $throws_union_type;
    }

    protected function getConfiguredIgnoreThrowsUnionType(): UnionType
    {
        return ThrowAnalyzerPlugin::$configured_ignore_throws_union_type ?? (ThrowAnalyzerPlugin::$configured_ignore_throws_union_type = $this->calculateConfiguredIgnoreThrowsUnionType());
    }

    /**
     * Check if the user wants to warn about a given throw type.
     */
    protected function shouldWarnAboutThrowType(Type $type): bool
    {
        $ignore_union_type = $this->getConfiguredIgnoreThrowsUnionType();
        if ($ignore_union_type->isEmpty()) {
            return true;
        }
        return !$type->isSubtypeOfAnyTypeInSet($ignore_union_type->getTypeSet(), $this->code_base);
    }
}

/**
 * Visits throw statements to compares them against the phpdoc (at)throws annotations in the function-like scope,
 * as well as to check if the functions invoked within the implementation may throw
 * are either caught or documented by the (at)throws annotation.
 */
class ThrowRecursiveVisitor extends ThrowVisitor
{
    /**
     * @override
     */
    public function visitCall(Node $node): void
    {
        $context = $this->context;
        if (!$context->isInFunctionLikeScope()) {
            return;
        }
        if ($node->children['args']->kind === ast\AST_CALLABLE_CONVERT) {
            // This is creating a callable instead of calling the function.
            return;
        }
        $code_base = $this->code_base;
        $analyzed_function = $context->getFunctionLikeInScope($code_base);
        try {
            $function_list_generator = (new ContextNode(
                $code_base,
                $context,
                $node->children['expr']
            ))->getFunctionFromNode();

            foreach ($function_list_generator as $invoked_function) {
                // Check the types that can be thrown by this call.
                $this->warnAboutPossiblyThrownType(
                    $node,
                    $analyzed_function,
                    $this->withoutCaughtUnionTypes($invoked_function->getOwnThrowsUnionType(), false)
                );
            }
        } catch (CodeBaseException $_) {
            // ignore it.
        }
    }

    /**
     * @override
     */
    public function visitNullsafeMethodCall(Node $node): void
    {
        $this->visitMethodCall($node);
    }

    /**
     * @override
     */
    public function visitMethodCall(Node $node): void
    {
        $context = $this->context;
        if (!$context->isInFunctionLikeScope()) {
            return;
        }
        if ($node->children['args']->kind === ast\AST_CALLABLE_CONVERT) {
            // This is creating a callable instead of calling the method.
            return;
        }
        $code_base = $this->code_base;
        $method_name = $node->children['method'];

        if (!\is_string($method_name)) {
            $method_name = UnionTypeVisitor::anyStringLiteralForNode($code_base, $context, $method_name);
            if (!\is_string($method_name)) {
                return;
            }
        }

        try {
            $invoked_method = (new ContextNode(
                $code_base,
                $context,
                $node
            ))->getMethod($method_name, false, true);
        } catch (IssueException | NodeException $_) {
            // do nothing, PostOrderAnalysisVisitor should catch this
            return;
        }
        $analyzed_function = $context->getFunctionLikeInScope($code_base);
        // Check the types that can be thrown by this call.
        $this->warnAboutPossiblyThrownType(
            $node,
            $analyzed_function,
            $this->withoutCaughtUnionTypes($invoked_method->getOwnThrowsUnionType(), false),
            $invoked_method
        );
    }

    /**
     * @override
     */
    public function visitStaticCall(Node $node): void
    {
        $context = $this->context;
        if (!$context->isInFunctionLikeScope()) {
            return;
        }
        if ($node->children['args']->kind === ast\AST_CALLABLE_CONVERT) {
            // This is creating a callable instead of calling the method.
            return;
        }
        $code_base = $this->code_base;
        $method_name = $node->children['method'];
        if (!\is_string($method_name)) {
            $method_name = UnionTypeVisitor::anyStringLiteralForNode($code_base, $context, $method_name);
            if (!\is_string($method_name)) {
                return;
            }
        }
        try {
            // Get a reference to the method being called
            $invoked_method = (new ContextNode(
                $code_base,
                $context,
                $node
            ))->getMethod($method_name, true, true);
        } catch (\Exception $_) {
            // Ignore IssueException, unexpected exceptions, etc.
            return;
        }

        $analyzed_function = $context->getFunctionLikeInScope($code_base);

        // Check the types that can be thrown by this call.
        $this->warnAboutPossiblyThrownType(
            $node,
            $analyzed_function,
            $this->withoutCaughtUnionTypes($invoked_method->getOwnThrowsUnionType(), false),
            $invoked_method
        );
    }
}
