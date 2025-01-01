<?php

declare(strict_types=1);

namespace Phan;

use AssertionError;
use InvalidArgumentException;
use Phan\Language\Context;
use Phan\Language\Element\Attribute;
use Phan\Language\Element\TypedElement;
use Phan\Language\Element\UnaddressableTypedElement;
use Phan\Language\FQSEN;
use Phan\Language\Type;
use Phan\Language\UnionType;
use Phan\Library\ConversionSpec;
use Phan\Plugin\ConfigPluginSet;
use Stringable;

/**
 * An issue emitted during analysis.
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 * @SuppressWarnings(PHPMD.ConstantNamingConventions) these constant names are deliberately used to match the values
 * @phan-pure
 */
class Issue
{
    // phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
    // this is deliberate for issue names
    // Issue::CATEGORY_SYNTAX
    public const SyntaxError                    = 'PhanSyntaxError';
    public const InvalidConstantExpression      = 'PhanInvalidConstantExpression';
    public const InvalidNode                    = 'PhanInvalidNode';
    public const InvalidWriteToTemporaryExpression = 'PhanInvalidWriteToTemporaryExpression';
    public const InvalidTraitUse                = 'PhanInvalidTraitUse';
    public const ContinueTargetingSwitch        = 'PhanContinueTargetingSwitch';
    public const ContinueOrBreakNotInLoop       = 'PhanContinueOrBreakNotInLoop';
    public const ContinueOrBreakTooManyLevels   = 'PhanContinueOrBreakTooManyLevels';
    public const SyntaxCompileWarning           = 'PhanSyntaxCompileWarning';
    public const SyntaxEmptyListArrayDestructuring = 'PhanSyntaxEmptyListArrayDestructuring';
    public const SyntaxMixedKeyNoKeyArrayDestructuring = 'PhanSyntaxMixedKeyNoKeyArrayDestructuring';
    public const SyntaxReturnExpectedValue      = 'PhanSyntaxReturnExpectedValue';
    public const SyntaxReturnValueInVoid        = 'PhanSyntaxReturnValueInVoid';
    public const SyntaxReturnStatementInNever   = 'PhanSyntaxReturnStatementInNever';
    public const SyntaxEnumCaseExpectedValue    = 'PhanSyntaxEnumCaseExpectedValue';
    public const SyntaxEnumCaseUnexpectedValue  = 'PhanSyntaxEnumCaseUnexpectedValue';
    public const PrivateFinalMethod             = 'PhanPrivateFinalMethod';
    public const PrivateFinalConstant           = 'PhanPrivateFinalConstant';
    public const ReadonlyPropertyMissingType    = 'PhanReadonlyPropertyMissingType';

    // Issue::CATEGORY_UNDEFINED
    public const AmbiguousTraitAliasSource = 'PhanAmbiguousTraitAliasSource';
    public const ClassContainsAbstractMethodInternal = 'PhanClassContainsAbstractMethodInternal';
    public const ClassContainsAbstractMethod = 'PhanClassContainsAbstractMethod';
    public const EmptyFile                 = 'PhanEmptyFile';
    public const MissingRequireFile        = 'PhanMissingRequireFile';
    public const InvalidRequireFile        = 'PhanInvalidRequireFile';
    public const ParentlessClass           = 'PhanParentlessClass';
    public const RequiredTraitNotAdded     = 'PhanRequiredTraitNotAdded';
    public const TraitParentReference      = 'PhanTraitParentReference';
    public const UndeclaredAliasedMethodOfTrait = 'PhanUndeclaredAliasedMethodOfTrait';
    public const UndeclaredClass           = 'PhanUndeclaredClass';
    public const UndeclaredClassAliasOriginal = 'PhanUndeclaredClassAliasOriginal';
    public const UndeclaredClassCatch      = 'PhanUndeclaredClassCatch';
    public const UndeclaredClassConstant   = 'PhanUndeclaredClassConstant';
    public const UndeclaredClassInstanceof = 'PhanUndeclaredClassInstanceof';
    public const UndeclaredClassMethod     = 'PhanUndeclaredClassMethod';
    public const UndeclaredClassProperty   = 'PhanUndeclaredClassProperty';
    public const UndeclaredClassReference  = 'PhanUndeclaredClassReference';
    public const UndeclaredClassStaticProperty = 'PhanUndeclaredClassStaticProperty';
    public const UndeclaredClassAttribute  = 'PhanUndeclaredClassAttribute';
    public const UndeclaredClosureScope    = 'PhanUndeclaredClosureScope';
    public const UndeclaredConstant        = 'PhanUndeclaredConstant';
    // Sadly, PhanUndeclaredClassConstant already exists and means the class is undeclared
    public const UndeclaredConstantOfClass = 'PhanUndeclaredConstantOfClass';
    public const UndeclaredMagicConstant   = 'PhanUndeclaredMagicConstant';
    public const UndeclaredExtendedClass   = 'PhanUndeclaredExtendedClass';
    public const UndeclaredFunction        = 'PhanUndeclaredFunction';
    public const UndeclaredInterface       = 'PhanUndeclaredInterface';
    public const UndeclaredMethod          = 'PhanUndeclaredMethod';
    public const PossiblyUndeclaredMethod  = 'PhanPossiblyUndeclaredMethod';
    public const UndeclaredProperty        = 'PhanUndeclaredProperty';
    public const PossiblyUndeclaredProperty = 'PhanPossiblyUndeclaredProperty';
    public const PossiblyUndeclaredPropertyOfClass = 'PhanPossiblyUndeclaredPropertyOfClass';
    public const UndeclaredStaticMethod    = 'PhanUndeclaredStaticMethod';
    public const UndeclaredStaticProperty  = 'PhanUndeclaredStaticProperty';
    public const UndeclaredTrait           = 'PhanUndeclaredTrait';
    public const UndeclaredTypeParameter   = 'PhanUndeclaredTypeParameter';
    public const UndeclaredTypeReturnType  = 'PhanUndeclaredTypeReturnType';
    public const UndeclaredTypeProperty    = 'PhanUndeclaredTypeProperty';
    public const UndeclaredTypeClassConstant = 'PhanUndeclaredTypeClassConstant';
    public const UndeclaredTypeThrowsType  = 'PhanUndeclaredTypeThrowsType';
    public const UndeclaredVariable        = 'PhanUndeclaredVariable';
    public const PossiblyUndeclaredVariable = 'PhanPossiblyUndeclaredVariable';
    public const UndeclaredGlobalVariable  = 'PhanUndeclaredGlobalVariable';
    public const PossiblyUndeclaredGlobalVariable  = 'PhanPossiblyUndeclaredGlobalVariable';
    public const UndeclaredThis            = 'PhanUndeclaredThis';
    public const UndeclaredVariableDim     = 'PhanUndeclaredVariableDim';
    public const UndeclaredVariableAssignOp = 'PhanUndeclaredVariableAssignOp';
    public const UndeclaredClassInCallable = 'PhanUndeclaredClassInCallable';
    public const UndeclaredStaticMethodInCallable = 'PhanUndeclaredStaticMethodInCallable';
    public const UndeclaredFunctionInCallable = 'PhanUndeclaredFunctionInCallable';
    public const UndeclaredMethodInCallable = 'PhanUndeclaredMethodInCallable';
    public const UndeclaredInvokeInCallable = 'PhanUndeclaredInvokeInCallable';
    public const EmptyFQSENInCallable      = 'PhanEmptyFQSENInCallable';
    public const InvalidFQSENInCallable    = 'PhanInvalidFQSENInCallable';
    public const EmptyFQSENInClasslike     = 'PhanEmptyFQSENInClasslike';
    public const InvalidFQSENInClasslike   = 'PhanInvalidFQSENInClasslike';
    public const PossiblyUnsetPropertyOfThis = 'PhanPossiblyUnsetPropertyOfThis';

    // Issue::CATEGORY_TYPE
    public const NonClassMethodCall                = 'PhanNonClassMethodCall';
    public const PossiblyNonClassMethodCall        = 'PhanPossiblyNonClassMethodCall';
    public const TypeArrayOperator                 = 'PhanTypeArrayOperator';
    public const TypeInvalidBitwiseBinaryOperator  = 'PhanTypeInvalidBitwiseBinaryOperator';
    public const TypeMismatchBitwiseBinaryOperands = 'PhanTypeMismatchBitwiseBinaryOperands';
    public const TypeArraySuspicious               = 'PhanTypeArraySuspicious';
    public const TypeArrayUnsetSuspicious          = 'PhanTypeArrayUnsetSuspicious';
    public const TypeArraySuspiciousNullable       = 'PhanTypeArraySuspiciousNullable';
    public const TypeArraySuspiciousNull           = 'PhanTypeArraySuspiciousNull';
    public const TypeSuspiciousIndirectVariable    = 'PhanTypeSuspiciousIndirectVariable';
    public const TypeObjectUnsetDeclaredProperty   = 'PhanTypeObjectUnsetDeclaredProperty';
    public const TypeModifyImmutableObjectProperty            = 'PhanTypeModifyImmutableObjectProperty';
    public const TypeComparisonFromArray   = 'PhanTypeComparisonFromArray';
    public const TypeComparisonToArray     = 'PhanTypeComparisonToArray';
    public const TypeConversionFromArray   = 'PhanTypeConversionFromArray';
    public const TypeInstantiateAbstract   = 'PhanTypeInstantiateAbstract';
    public const TypeInstantiateAbstractStatic = 'PhanTypeInstantiateAbstractStatic';
    public const TypeInstantiateEnum       = 'PhanTypeInstantiateEnum';
    public const TypeInstantiateInterface  = 'PhanTypeInstantiateInterface';
    public const TypeInstantiateTrait      = 'PhanTypeInstantiateTrait';
    public const TypeInstantiateTraitStaticOrSelf = 'PhanTypeInstantiateTraitStaticOrSelf';
    public const TypeInvalidCloneNotObject = 'PhanTypeInvalidCloneNotObject';
    public const TypePossiblyInvalidCloneNotObject = 'PhanTypePossiblyInvalidCloneNotObject';
    public const TypeInvalidClosureScope   = 'PhanTypeInvalidClosureScope';
    public const TypeInvalidLeftOperand    = 'PhanTypeInvalidLeftOperand';
    public const TypeInvalidRightOperand   = 'PhanTypeInvalidRightOperand';
    public const TypeInvalidLeftOperandOfAdd  = 'PhanTypeInvalidLeftOperandOfAdd';
    public const TypeInvalidRightOperandOfAdd = 'PhanTypeInvalidRightOperandOfAdd';
    public const TypeInvalidLeftOperandOfNumericOp = 'PhanTypeInvalidLeftOperandOfNumericOp';
    public const TypeInvalidRightOperandOfNumericOp = 'PhanTypeInvalidRightOperandOfNumericOp';
    public const TypeInvalidLeftOperandOfIntegerOp = 'PhanTypeInvalidLeftOperandOfIntegerOp';
    public const TypeInvalidRightOperandOfIntegerOp = 'PhanTypeInvalidRightOperandOfIntegerOp';
    public const TypeInvalidLeftOperandOfBitwiseOp = 'PhanTypeInvalidLeftOperandOfBitwiseOp';
    public const TypeInvalidRightOperandOfBitwiseOp = 'PhanTypeInvalidRightOperandOfBitwiseOp';
    public const TypeInvalidUnaryOperandNumeric = 'PhanTypeInvalidUnaryOperandNumeric';
    public const TypeInvalidUnaryOperandBitwiseNot = 'PhanTypeInvalidUnaryOperandBitwiseNot';
    public const TypeInvalidUnaryOperandIncOrDec = 'PhanTypeInvalidUnaryOperandIncOrDec';
    public const TypeInvalidInstanceof     = 'PhanTypeInvalidInstanceof';
    public const TypeInvalidDimOffset      = 'PhanTypeInvalidDimOffset';
    public const TypeInvalidDimOffsetArrayDestructuring = 'PhanTypeInvalidDimOffsetArrayDestructuring';
    public const TypePossiblyInvalidDimOffset           = 'PhanTypePossiblyInvalidDimOffset';
    public const TypeInvalidCallExpressionAssignment    = 'PhanTypeInvalidCallExpressionAssignment';
    public const TypeInvalidExpressionArrayDestructuring = 'PhanTypeInvalidExpressionArrayDestructuring';
    public const TypeInvalidThrowsNonObject             = 'PhanTypeInvalidThrowsNonObject';
    public const TypeInvalidThrowsNonThrowable          = 'PhanTypeInvalidThrowsNonThrowable';
    public const TypeInvalidThrowStatementNonThrowable          = 'PhanTypeInvalidThrowStatementNonThrowable';
    public const TypeInvalidThrowsIsTrait               = 'PhanTypeInvalidThrowsIsTrait';
    public const TypeInvalidThrowsIsInterface           = 'PhanTypeInvalidThrowsIsInterface';
    public const TypeMagicVoidWithReturn                = 'PhanTypeMagicVoidWithReturn';
    public const TypeMismatchArgument                   = 'PhanTypeMismatchArgument';
    public const TypeMismatchArgumentProbablyReal       = 'PhanTypeMismatchArgumentProbablyReal';
    public const TypeMismatchArgumentReal               = 'PhanTypeMismatchArgumentReal';
    public const TypeMismatchArgumentNullable           = 'PhanTypeMismatchArgumentNullable';
    public const TypeMismatchArgumentInternal           = 'PhanTypeMismatchArgumentInternal';
    public const TypeMismatchArgumentInternalProbablyReal = 'PhanTypeMismatchArgumentInternalProbablyReal';
    public const TypeMismatchArgumentInternalReal       = 'PhanTypeMismatchArgumentInternalReal';
    public const TypeMismatchArgumentNullableInternal   = 'PhanTypeMismatchArgumentNullableInternal';
    public const TypeMismatchArgumentSuperType          = 'PhanTypeMismatchArgumentSuperType';
    public const PartialTypeMismatchArgument            = 'PhanPartialTypeMismatchArgument';
    public const PartialTypeMismatchArgumentInternal    = 'PhanPartialTypeMismatchArgumentInternal';
    public const PossiblyNullTypeArgument  = 'PhanPossiblyNullTypeArgument';
    public const PossiblyNullTypeArgumentInternal = 'PhanPossiblyNullTypeArgumentInternal';
    public const PossiblyFalseTypeArgument  = 'PhanPossiblyFalseTypeArgument';
    public const PossiblyFalseTypeArgumentInternal = 'PhanPossiblyFalseTypeArgumentInternal';

    public const TypeMismatchDefault       = 'PhanTypeMismatchDefault';
    public const TypeMismatchDefaultIntersection = 'PhanTypeMismatchDefaultIntersection';
    public const TypeMismatchDimAssignment = 'PhanTypeMismatchDimAssignment';
    public const TypeMismatchDimEmpty      = 'PhanTypeMismatchDimEmpty';
    public const TypeMismatchDimFetch      = 'PhanTypeMismatchDimFetch';
    public const TypeMismatchDimFetchNullable = 'PhanTypeMismatchDimFetchNullable';
    public const TypeMismatchUnpackKey     = 'PhanTypeMismatchUnpackKey';
    public const TypeMismatchUnpackKeyArraySpread = 'PhanTypeMismatchUnpackKeyArraySpread';
    public const TypeMismatchUnpackValue   = 'PhanTypeMismatchUnpackValue';
    public const TypeMismatchArrayDestructuringKey = 'PhanTypeMismatchArrayDestructuringKey';
    public const TypeMismatchVariadicComment = 'PhanMismatchVariadicComment';
    public const TypeMismatchVariadicParam = 'PhanMismatchVariadicParam';
    public const TypeMismatchForeach       = 'PhanTypeMismatchForeach';
    public const TypeNoAccessiblePropertiesForeach = 'PhanTypeNoAccessiblePropertiesForeach';
    public const TypeNoPropertiesForeach = 'PhanTypeNoPropertiesForeach';
    public const TypeSuspiciousNonTraversableForeach = 'PhanTypeSuspiciousNonTraversableForeach';
    public const TypeMismatchProperty      = 'PhanTypeMismatchProperty';
    public const PossiblyNullTypeMismatchProperty = 'PhanPossiblyNullTypeMismatchProperty';
    public const PossiblyFalseTypeMismatchProperty = 'PhanPossiblyFalseTypeMismatchProperty';
    public const PartialTypeMismatchProperty = 'PhanPartialTypeMismatchProperty';
    public const TypeMismatchReturn        = 'PhanTypeMismatchReturn';
    public const TypeMismatchReturnNullable = 'PhanTypeMismatchReturnNullable';
    public const TypeMismatchReturnProbablyReal = 'PhanTypeMismatchReturnProbablyReal';
    public const TypeMismatchReturnReal     = 'PhanTypeMismatchReturnReal';
    public const TypeMismatchReturnSuperType = 'PhanTypeMismatchReturnSuperType';
    public const PartialTypeMismatchReturn = 'PhanPartialTypeMismatchReturn';
    public const PossiblyNullTypeReturn  = 'PhanPossiblyNullTypeReturn';
    public const PossiblyFalseTypeReturn  = 'PhanPossiblyFalseTypeReturn';
    public const TypeMismatchDeclaredReturn = 'PhanTypeMismatchDeclaredReturn';
    public const TypeMismatchDeclaredReturnNullable = 'PhanTypeMismatchDeclaredReturnNullable';
    public const TypeMismatchDeclaredParam = 'PhanTypeMismatchDeclaredParam';
    public const TypeMismatchDeclaredParamNullable = 'PhanTypeMismatchDeclaredParamNullable';
    public const TypeMissingReturn         = 'PhanTypeMissingReturn';
    public const TypeMissingReturnReal     = 'PhanTypeMissingReturnReal';
    public const TypeNonVarPassByRef       = 'PhanTypeNonVarPassByRef';
    public const TypeNonVarReturnByRef       = 'PhanTypeNonVarReturnByRef';
    public const TypeParentConstructorCalled = 'PhanTypeParentConstructorCalled';
    public const TypeSuspiciousEcho        = 'PhanTypeSuspiciousEcho';
    public const TypeSuspiciousStringExpression = 'PhanTypeSuspiciousStringExpression';
    public const TypeVoidAssignment        = 'PhanTypeVoidAssignment';
    public const TypeVoidArgument          = 'PhanTypeVoidArgument';
    public const TypeVoidExpression        = 'PhanTypeVoidExpression';
    public const TypePossiblyInvalidCallable = 'PhanTypePossiblyInvalidCallable';
    public const TypeInvalidCallable = 'PhanTypeInvalidCallable';
    public const TypeInvalidCallableArraySize = 'PhanTypeInvalidCallableArraySize';
    public const TypeInvalidCallableArrayKey = 'PhanTypeInvalidCallableArrayKey';
    public const TypeInvalidCallableObjectOfMethod = 'PhanTypeInvalidCallableObjectOfMethod';
    public const TypeExpectedObject        = 'PhanTypeExpectedObject';
    public const TypeExpectedObjectOrClassName = 'PhanTypeExpectedObjectOrClassName';
    public const TypeExpectedObjectPropAccess = 'PhanTypeExpectedObjectPropAccess';
    public const TypeExpectedObjectPropAccessButGotNull = 'PhanTypeExpectedObjectPropAccessButGotNull';
    public const TypeExpectedObjectStaticPropAccess = 'PhanTypeExpectedObjectStaticPropAccess';

    public const TypeMismatchGeneratorYieldValue = 'PhanTypeMismatchGeneratorYieldValue';
    public const TypeMismatchGeneratorYieldKey   = 'PhanTypeMismatchGeneratorYieldKey';
    public const TypeInvalidYieldFrom            = 'PhanTypeInvalidYieldFrom';
    public const TypeInvalidMethodName           = 'PhanTypeInvalidMethodName';
    public const TypeInvalidStaticMethodName     = 'PhanTypeInvalidStaticMethodName';
    public const TypeInvalidCallableMethodName   = 'PhanTypeInvalidCallableMethodName';
    public const TypeInvalidRequire              = 'PhanTypeInvalidRequire';
    public const TypeInvalidEval                 = 'PhanTypeInvalidEval';
    public const RelativePathUsed                = 'PhanRelativePathUsed';
    public const TypeInvalidTraitReturn          = 'PhanTypeInvalidTraitReturn';
    public const TypeInvalidTraitParam           = 'PhanTypeInvalidTraitParam';
    public const InfiniteRecursion               = 'PhanInfiniteRecursion';
    public const PossibleInfiniteRecursionSameParams = 'PhanPossiblyInfiniteRecursionSameParams';
    public const TypeComparisonToInvalidClass    = 'PhanTypeComparisonToInvalidClass';
    public const TypeComparisonToInvalidClassType = 'PhanTypeComparisonToInvalidClassType';
    public const TypeInvalidPropertyName = 'PhanTypeInvalidPropertyName';
    public const TypeInvalidStaticPropertyName = 'PhanTypeInvalidStaticPropertyName';
    public const TypeErrorInInternalCall = 'PhanTypeErrorInInternalCall';
    public const TypeErrorInOperation = 'PhanTypeErrorInOperation';
    public const TypeMismatchPropertyDefault        = 'PhanTypeMismatchPropertyDefault';
    public const TypeMismatchPropertyDefaultReal    = 'PhanTypeMismatchPropertyDefaultReal';
    public const TypeMismatchPropertyProbablyReal  = 'PhanTypeMismatchPropertyProbablyReal';
    public const TypeMismatchPropertyReal          = 'PhanTypeMismatchPropertyReal';
    public const TypeMismatchPropertyRealByRef     = 'PhanTypeMismatchPropertyRealByRef';
    public const TypeMismatchPropertyByRef         = 'PhanTypeMismatchPropertyByRef';
    public const ImpossibleCondition               = 'PhanImpossibleCondition';
    public const ImpossibleConditionInLoop         = 'PhanImpossibleConditionInLoop';
    public const ImpossibleConditionInGlobalScope  = 'PhanImpossibleConditionInGlobalScope';
    public const RedundantCondition                = 'PhanRedundantCondition';
    public const RedundantConditionInLoop          = 'PhanRedundantConditionInLoop';
    public const RedundantConditionInGlobalScope   = 'PhanRedundantConditionInGlobalScope';
    public const InfiniteLoop                      = 'PhanInfiniteLoop';
    public const PossiblyInfiniteLoop              = 'PhanPossiblyInfiniteLoop';
    public const ImpossibleTypeComparison          = 'PhanImpossibleTypeComparison';
    public const ImpossibleTypeComparisonInLoop    = 'PhanImpossibleTypeComparisonInLoop';
    public const ImpossibleTypeComparisonInGlobalScope = 'PhanImpossibleTypeComparisonInGlobalScope';
    public const SuspiciousValueComparison             = 'PhanSuspiciousValueComparison';
    public const SuspiciousValueComparisonInLoop       = 'PhanSuspiciousValueComparisonInLoop';
    public const SuspiciousValueComparisonInGlobalScope = 'PhanSuspiciousValueComparisonInGlobalScope';
    public const SuspiciousLoopDirection               = 'PhanSuspiciousLoopDirection';
    public const SuspiciousWeakTypeComparison          = 'PhanSuspiciousWeakTypeComparison';
    public const SuspiciousWeakTypeComparisonInLoop    = 'PhanSuspiciousWeakTypeComparisonInLoop';
    public const SuspiciousWeakTypeComparisonInGlobalScope    = 'PhanSuspiciousWeakTypeComparisonInGlobalScope';
    public const SuspiciousTruthyCondition         = 'PhanSuspiciousTruthyCondition';
    public const SuspiciousTruthyString            = 'PhanSuspiciousTruthyString';
    public const CoalescingNeverNull               = 'PhanCoalescingNeverNull';
    public const CoalescingNeverNullInLoop         = 'PhanCoalescingNeverNullInLoop';
    public const CoalescingNeverNullInGlobalScope  = 'PhanCoalescingNeverNullInGlobalScope';
    public const CoalescingAlwaysNull              = 'PhanCoalescingAlwaysNull';
    public const CoalescingAlwaysNullInLoop        = 'PhanCoalescingAlwaysNullInLoop';
    public const CoalescingAlwaysNullInGlobalScope = 'PhanCoalescingAlwaysNullInGlobalScope';
    public const CoalescingNeverUndefined          = 'PhanCoalescingNeverUndefined';
    public const TypeMismatchArgumentPropertyReference = 'PhanTypeMismatchArgumentPropertyReference';
    public const TypeMismatchArgumentPropertyReferenceReal = 'PhanTypeMismatchArgumentPropertyReferenceReal';
    public const DivisionByZero = 'PhanDivisionByZero';
    public const ModuloByZero = 'PhanModuloByZero';
    public const PowerOfZero = 'PhanPowerOfZero';
    public const InvalidMixin = 'PhanInvalidMixin';
    public const IncompatibleRealPropertyType = 'PhanIncompatibleRealPropertyType';
    public const AttributeNonClass = 'PhanAttributeNonClass';
    public const AttributeNonAttribute = 'PhanAttributeNonAttribute';
    public const AttributeNonRepeatable = 'PhanAttributeNonRepeatable';
    public const AttributeWrongTarget = 'PhanAttributeWrongTarget';
    public const TypeUnexpectedEnumCaseType = 'PhanTypeUnexpectedEnumCaseType';
    public const InstanceMethodWithNoEnumCases = 'PhanInstanceMethodWithNoEnumCases';
    public const EnumCannotHaveProperties = 'PhanEnumCannotHaveProperties';
    public const EnumForbiddenMagicMethod = 'PhanEnumForbiddenMagicMethod';
    public const EnumCannotImplement = 'PhanEnumCannotImplement';
    public const ImpossibleIntersectionType = 'PhanImpossibleIntersectionType';
    public const TypeInvalidArrayKey = 'PhanTypeInvalidArrayKey';
    public const TypeInvalidArrayKeyLiteral = 'PhanTypeInvalidArrayKeyLiteral';

    // Issue::CATEGORY_ANALYSIS
    public const Unanalyzable              = 'PhanUnanalyzable';
    public const UnanalyzableInheritance   = 'PhanUnanalyzableInheritance';
    public const InvalidConstantFQSEN      = 'PhanInvalidConstantFQSEN';
    public const ReservedConstantName      = 'PhanReservedConstantName';

    // Issue::CATEGORY_VARIABLE
    public const VariableUseClause         = 'PhanVariableUseClause';

    // Issue::CATEGORY_STATIC
    public const StaticCallToNonStatic            = 'PhanStaticCallToNonStatic';
    public const StaticPropIsStaticType           = 'PhanStaticPropIsStaticType';
    public const AbstractStaticMethodCall         = 'PhanAbstractStaticMethodCall';
    public const AbstractStaticMethodCallInStatic = 'PhanAbstractStaticMethodCallInStatic';
    public const AbstractStaticMethodCallInTrait  = 'PhanAbstractStaticMethodCallInTrait';
    public const StaticClassAccessWithStaticVariable = 'PhanStaticClassAccessWithStaticVariable';

    // Issue::CATEGORY_CONTEXT
    public const ContextNotObject           = 'PhanContextNotObject';
    public const ContextNotObjectInCallable = 'PhanContextNotObjectInCallable';
    public const ContextNotObjectUsingSelf  = 'PhanContextNotObjectUsingSelf';
    public const SuspiciousMagicConstant    = 'PhanSuspiciousMagicConstant';

    // Issue::CATEGORY_DEPRECATED
    public const DeprecatedClass           = 'PhanDeprecatedClass';
    public const DeprecatedInterface       = 'PhanDeprecatedInterface';
    public const DeprecatedTrait           = 'PhanDeprecatedTrait';
    public const DeprecatedFunction        = 'PhanDeprecatedFunction';
    public const DeprecatedFunctionInternal = 'PhanDeprecatedFunctionInternal';
    public const DeprecatedProperty        = 'PhanDeprecatedProperty';
    public const DeprecatedClassConstant   = 'PhanDeprecatedClassConstant';
    public const DeprecatedCaseInsensitiveDefine = 'PhanDeprecatedCaseInsensitiveDefine';
    public const DeprecatedPartiallySupportedCallable = 'PhanDeprecatedPartiallySupportedCallable';
    public const DeprecatedPartiallySupportedCallableAlternateScope = 'PhanDeprecatedPartiallySupportedCallableAlternateScope';
    public const DeprecatedEncapsVar       = 'PhanDeprecatedEncapsVar';
    public const DeprecatedImplicitNullableParam = 'PhanDeprecatedImplicitNullableParam';

    // Issue::CATEGORY_PARAMETER
    public const ParamReqAfterOpt          = 'PhanParamReqAfterOpt';
    public const ParamSpecial1             = 'PhanParamSpecial1';
    public const ParamSpecial2             = 'PhanParamSpecial2';
    public const ParamSpecial3             = 'PhanParamSpecial3';
    public const ParamSpecial4             = 'PhanParamSpecial4';
    public const ParamSuspiciousOrder      = 'PhanParamSuspiciousOrder';
    public const ParamTooFew               = 'PhanParamTooFew';
    public const ParamTooFewUnpack         = 'PhanParamTooFewUnpack';
    public const ParamTooFewInternal       = 'PhanParamTooFewInternal';
    public const ParamTooFewInternalUnpack = 'PhanParamTooFewInternalUnpack';
    public const ParamTooFewCallable       = 'PhanParamTooFewCallable';
    public const ParamTooFewInPHPDoc       = 'PhanParamTooFewInPHPDoc';
    public const ParamTooMany              = 'PhanParamTooMany';
    public const ParamTooManyUnpack        = 'PhanParamTooManyUnpack';
    public const ParamTooManyInternal      = 'PhanParamTooManyInternal';
    public const ParamTooManyUnpackInternal = 'PhanParamTooManyUnpackInternal';
    public const ParamTooManyCallable      = 'PhanParamTooManyCallable';
    public const ParamTypeMismatch         = 'PhanParamTypeMismatch';
    public const ParamSignatureMismatch    = 'PhanParamSignatureMismatch';
    public const ParamSignatureMismatchInternal = 'PhanParamSignatureMismatchInternal';
    public const ParamRedefined            = 'PhanParamRedefined';
    public const ParamMustBeUserDefinedClassname = 'PhanParamMustBeUserDefinedClassname';

    public const ParamSignatureRealMismatchReturnType                        = 'PhanParamSignatureRealMismatchReturnType';
    public const ParamSignatureRealMismatchReturnTypeInternal                = 'PhanParamSignatureRealMismatchReturnTypeInternal';
    public const ParamSignaturePHPDocMismatchReturnType                      = 'PhanParamSignaturePHPDocMismatchReturnType';
    public const ParamSignatureRealMismatchTooManyRequiredParameters         = 'PhanParamSignatureRealMismatchTooManyRequiredParameters';
    public const ParamSignatureRealMismatchTooManyRequiredParametersInternal = 'PhanParamSignatureRealMismatchTooManyRequiredParametersInternal';
    public const ParamSignaturePHPDocMismatchTooManyRequiredParameters       = 'PhanParamSignaturePHPDocMismatchTooManyRequiredParameters';
    public const ParamSignatureRealMismatchTooFewParameters                  = 'PhanParamSignatureRealMismatchTooFewParameters';
    public const ParamSignatureRealMismatchTooFewParametersInternal          = 'PhanParamSignatureRealMismatchTooFewParametersInternal';
    public const ParamSignaturePHPDocMismatchTooFewParameters                = 'PhanParamSignaturePHPDocMismatchTooFewParameters';
    public const ParamSignatureRealMismatchHasParamType                      = 'PhanParamSignatureRealMismatchHasParamType';
    public const ParamSignatureRealMismatchHasParamTypeInternal              = 'PhanParamSignatureRealMismatchHasParamTypeInternal';
    public const ParamSignaturePHPDocMismatchHasParamType                    = 'PhanParamSignaturePHPDocMismatchHasParamType';
    public const ParamSignatureRealMismatchHasNoParamType                    = 'PhanParamSignatureRealMismatchHasNoParamType';
    public const ParamSignatureRealMismatchHasNoParamTypeInternal            = 'PhanParamSignatureRealMismatchHasNoParamTypeInternal';
    public const ParamSignaturePHPDocMismatchHasNoParamType                  = 'PhanParamSignaturePHPDocMismatchHasNoParamType';
    public const ParamSignatureRealMismatchParamIsReference                  = 'PhanParamSignatureRealMismatchParamIsReference';
    public const ParamSignatureRealMismatchParamIsReferenceInternal          = 'PhanParamSignatureRealMismatchParamIsReferenceInternal';
    public const ParamSignaturePHPDocMismatchParamIsReference                = 'PhanParamSignaturePHPDocMismatchParamIsReference';
    public const ParamSignatureRealMismatchParamIsNotReference               = 'PhanParamSignatureRealMismatchParamIsNotReference';
    public const ParamSignatureRealMismatchParamIsNotReferenceInternal       = 'PhanParamSignatureRealMismatchParamIsNotReferenceInternal';
    public const ParamSignaturePHPDocMismatchParamIsNotReference             = 'PhanParamSignaturePHPDocMismatchParamIsNotReference';
    public const ParamSignatureRealMismatchParamVariadic                     = 'PhanParamSignatureRealMismatchParamVariadic';
    public const ParamSignatureRealMismatchParamVariadicInternal             = 'PhanParamSignatureRealMismatchParamVariadicInternal';
    public const ParamSignaturePHPDocMismatchParamVariadic                   = 'PhanParamSignaturePHPDocMismatchParamVariadic';
    public const ParamSignatureRealMismatchParamNotVariadic                  = 'PhanParamSignatureRealMismatchParamNotVariadic';
    public const ParamSignatureRealMismatchParamNotVariadicInternal          = 'PhanParamSignatureRealMismatchParamNotVariadicInternal';
    public const ParamSignaturePHPDocMismatchParamNotVariadic                = 'PhanParamSignaturePHPDocMismatchParamNotVariadic';
    public const ParamSignatureRealMismatchParamType                         = 'PhanParamSignatureRealMismatchParamType';
    public const ParamSignatureRealMismatchParamTypeInternal                 = 'PhanParamSignatureRealMismatchParamTypeInternal';
    public const ParamSignaturePHPDocMismatchParamType                       = 'PhanParamSignaturePHPDocMismatchParamType';
    public const ParamNameIndicatingUnused                                   = 'PhanParamNameIndicatingUnused';
    public const ParamNameIndicatingUnusedInClosure                          = 'PhanParamNameIndicatingUnusedInClosure';
    public const UndeclaredNamedArgument                                     = 'PhanUndeclaredNamedArgument';
    public const UndeclaredNamedArgumentInternal                             = 'PhanUndeclaredNamedArgumentInternal';
    public const DuplicateNamedArgument                                      = 'PhanDuplicateNamedArgument';
    public const DuplicateNamedArgumentInternal                              = 'PhanDuplicateNamedArgumentInternal';
    public const DefinitelyDuplicateNamedArgument                            = 'PhanDefinitelyDuplicateNamedArgument';
    public const PositionalArgumentAfterNamedArgument                        = 'PhanPositionalArgumentAfterNamedArgument';
    public const ArgumentUnpackingUsedWithNamedArgument                      = 'PhanArgumentUnpackingUsedWithNamedArgument';
    public const MissingNamedArgument                                        = 'PhanMissingNamedArgument';
    public const MissingNamedArgumentInternal                                = 'PhanMissingNamedArgumentInternal';
    public const SuspiciousNamedArgumentForVariadic                          = 'PhanSuspiciousNamedArgumentForVariadic';
    public const SuspiciousNamedArgumentVariadicInternal                     = 'PhanSuspiciousNamedArgumentVariadicInternal';
    public const SuspiciousNamedArgumentVariadicInternalUnpack               = 'PhanSuspiciousNamedArgumentVariadicInternalUnpack';
    public const NoNamedArgument                                             = 'PhanNoNamedArgument';
    public const NoNamedArgumentVariadic                                     = 'PhanNoNamedArgumentVariadic';

    // Issue::CATEGORY_NOOP
    public const NoopArray                     = 'PhanNoopArray';
    public const NoopClosure                   = 'PhanNoopClosure';
    public const NoopConstant                  = 'PhanNoopConstant';
    public const NoopProperty                  = 'PhanNoopProperty';
    public const NoopArrayAccess               = 'PhanNoopArrayAccess';
    public const NoopVariable                  = 'PhanNoopVariable';
    public const NoopUnaryOperator             = 'PhanNoopUnaryOperator';
    public const NoopBinaryOperator            = 'PhanNoopBinaryOperator';
    public const NoopStringLiteral             = 'PhanNoopStringLiteral';
    public const NoopEncapsulatedStringLiteral = 'PhanNoopEncapsulatedStringLiteral';
    public const NoopNumericLiteral            = 'PhanNoopNumericLiteral';
    public const NoopEmpty                     = 'PhanNoopEmpty';
    public const NoopIsset                     = 'PhanNoopIsset';
    public const NoopCast                      = 'PhanNoopCast';
    public const NoopTernary                   = 'PhanNoopTernary';
    public const NoopNew                       = 'PhanNoopNew';
    public const NoopNewNoSideEffects          = 'PhanNoopNewNoSideEffects';
    public const NoopSwitchCases               = 'PhanNoopSwitchCases';
    public const NoopMatchArms                 = 'PhanNoopMatchArms';
    public const NoopMatchExpression           = 'PhanNoopMatchExpression';
    public const NoopRepeatedSilenceOperator   = 'PhanNoopRepeatedSilenceOperator';
    public const UnreachableCatch              = 'PhanUnreachableCatch';
    public const UnreferencedClass             = 'PhanUnreferencedClass';
    public const UnreferencedFunction          = 'PhanUnreferencedFunction';
    public const UnreferencedPublicMethod      = 'PhanUnreferencedPublicMethod';
    public const UnreferencedProtectedMethod   = 'PhanUnreferencedProtectedMethod';
    public const UnreferencedPrivateMethod     = 'PhanUnreferencedPrivateMethod';
    public const UnreferencedPublicProperty    = 'PhanUnreferencedPublicProperty';
    public const UnreferencedProtectedProperty = 'PhanUnreferencedProtectedProperty';
    public const UnreferencedPrivateProperty   = 'PhanUnreferencedPrivateProperty';
    public const UnreferencedPHPDocProperty    = 'PhanUnreferencedPHPDocProperty';
    public const ReadOnlyPublicProperty        = 'PhanReadOnlyPublicProperty';
    public const ReadOnlyProtectedProperty     = 'PhanReadOnlyProtectedProperty';
    public const ReadOnlyPrivateProperty       = 'PhanReadOnlyPrivateProperty';
    public const ReadOnlyPHPDocProperty        = 'PhanReadOnlyPHPDocProperty';
    public const WriteOnlyPublicProperty       = 'PhanWriteOnlyPublicProperty';
    public const WriteOnlyProtectedProperty    = 'PhanWriteOnlyProtectedProperty';
    public const WriteOnlyPrivateProperty      = 'PhanWriteOnlyPrivateProperty';
    public const WriteOnlyPHPDocProperty       = 'PhanWriteOnlyPHPDocProperty';
    public const UnreferencedConstant          = 'PhanUnreferencedConstant';
    public const UnreferencedPublicClassConstant = 'PhanUnreferencedPublicClassConstant';
    public const UnreferencedProtectedClassConstant = 'PhanUnreferencedProtectedClassConstant';
    public const UnreferencedPrivateClassConstant = 'PhanUnreferencedPrivateClassConstant';
    public const UnreferencedEnumCase          = 'PhanUnreferencedEnumCase';
    public const UnreferencedClosure           = 'PhanUnreferencedClosure';
    public const UnreferencedUseNormal         = 'PhanUnreferencedUseNormal';
    public const UnreferencedUseFunction       = 'PhanUnreferencedUseFunction';
    public const UnreferencedUseConstant       = 'PhanUnreferencedUseConstant';
    public const DuplicateUseNormal            = 'PhanDuplicateUseNormal';
    public const DuplicateUseFunction          = 'PhanDuplicateUseFunction';
    public const DuplicateUseConstant          = 'PhanDuplicateUseConstant';
    public const UseNormalNoEffect             = 'PhanUseNormalNoEffect';
    public const UseNormalNamespacedNoEffect   = 'PhanUseNormalNamespacedNoEffect';
    public const UseFunctionNoEffect           = 'PhanUseFunctionNoEffect';
    public const UseConstantNoEffect           = 'PhanUseConstantNoEffect';
    public const EmptyPublicMethod = 'PhanEmptyPublicMethod';
    public const EmptyProtectedMethod = 'PhanEmptyProtectedMethod';
    public const EmptyPrivateMethod = 'PhanEmptyPrivateMethod';
    public const EmptyFunction = 'PhanEmptyFunction';
    public const EmptyClosure = 'PhanEmptyClosure';

    public const UnusedVariable                        = 'PhanUnusedVariable';
    public const UnusedPublicMethodParameter           = 'PhanUnusedPublicMethodParameter';
    public const UnusedPublicFinalMethodParameter      = 'PhanUnusedPublicFinalMethodParameter';
    public const UnusedPublicNoOverrideMethodParameter = 'PhanUnusedPublicNoOverrideMethodParameter';
    public const UnusedProtectedMethodParameter        = 'PhanUnusedProtectedMethodParameter';
    public const UnusedProtectedFinalMethodParameter   = 'PhanUnusedProtectedFinalMethodParameter';
    public const UnusedProtectedNoOverrideMethodParameter = 'PhanUnusedProtectedNoOverrideMethodParameter';
    public const UnusedPrivateMethodParameter          = 'PhanUnusedPrivateMethodParameter';
    public const UnusedPrivateFinalMethodParameter     = 'PhanUnusedPrivateFinalMethodParameter';
    public const UnusedClosureUseVariable              = 'PhanUnusedClosureUseVariable';
    public const ShadowedVariableInArrowFunc           = 'PhanShadowedVariableInArrowFunc';
    public const UnusedClosureParameter                = 'PhanUnusedClosureParameter';
    public const UnusedGlobalFunctionParameter         = 'PhanUnusedGlobalFunctionParameter';
    public const UnusedVariableValueOfForeachWithKey   = 'PhanUnusedVariableValueOfForeachWithKey';  // has higher false positive rates than UnusedVariable
    public const EmptyForeach                          = 'PhanEmptyForeach';
    public const EmptyForeachBody                      = 'PhanEmptyForeachBody';
    public const SideEffectFreeForeachBody             = 'PhanSideEffectFreeForeachBody';
    public const SideEffectFreeForBody                 = 'PhanSideEffectFreeForBody';
    public const SideEffectFreeWhileBody               = 'PhanSideEffectFreeWhileBody';
    public const SideEffectFreeDoWhileBody             = 'PhanSideEffectFreeDoWhileBody';
    public const EmptyYieldFrom                        = 'PhanEmptyYieldFrom';
    public const UselessBinaryAddRight                 = 'PhanUselessBinaryAddRight';
    public const SuspiciousBinaryAddLists              = 'PhanSuspiciousBinaryAddLists';
    public const UnusedVariableCaughtException         = 'PhanUnusedVariableCaughtException';  // has higher false positive rates than UnusedVariable
    public const UnusedGotoLabel                       = 'PhanUnusedGotoLabel';
    public const UnusedVariableReference               = 'PhanUnusedVariableReference';
    public const UnusedVariableStatic                  = 'PhanUnusedVariableStatic';
    public const UnusedVariableGlobal                  = 'PhanUnusedVariableGlobal';
    public const UnusedReturnBranchWithoutSideEffects  = 'PhanUnusedReturnBranchWithoutSideEffects';
    public const RedundantArrayValuesCall                  = 'PhanRedundantArrayValuesCall';
    public const VariableDefinitionCouldBeConstant     = 'PhanVariableDefinitionCouldBeConstant';
    public const VariableDefinitionCouldBeConstantEmptyArray = 'PhanVariableDefinitionCouldBeConstantEmptyArray';
    public const VariableDefinitionCouldBeConstantString = 'PhanVariableDefinitionCouldBeConstantString';
    public const VariableDefinitionCouldBeConstantFloat = 'PhanVariableDefinitionCouldBeConstantFloat';
    public const VariableDefinitionCouldBeConstantInt = 'PhanVariableDefinitionCouldBeConstantInt';
    public const VariableDefinitionCouldBeConstantTrue = 'PhanVariableDefinitionCouldBeConstantTrue';
    public const VariableDefinitionCouldBeConstantFalse = 'PhanVariableDefinitionCouldBeConstantFalse';
    public const VariableDefinitionCouldBeConstantNull = 'PhanVariableDefinitionCouldBeConstantNull';
    public const ProvidingUnusedParameter              = 'PhanProvidingUnusedParameter';
    public const ProvidingUnusedParameterOfClosure     = 'PhanProvidingUnusedParameterOfClosure';

    // Issue::CATEGORY_REDEFINE
    public const RedefineClass             = 'PhanRedefineClass';
    public const RedefineClassAlias        = 'PhanRedefineClassAlias';
    public const RedefineClassInternal     = 'PhanRedefineClassInternal';
    public const RedefineFunction          = 'PhanRedefineFunction';
    public const RedefineFunctionInternal  = 'PhanRedefineFunctionInternal';
    public const RedefineClassConstant     = 'PhanRedefineClassConstant';
    public const RedefineProperty          = 'PhanRedefineProperty';
    public const IncompatibleCompositionProp = 'PhanIncompatibleCompositionProp';
    public const IncompatibleCompositionMethod = 'PhanIncompatibleCompositionMethod';
    public const RedefinedUsedTrait            = 'PhanRedefinedUsedTrait';
    public const RedefinedInheritedInterface   = 'PhanRedefinedInheritedInterface';
    public const RedefinedExtendedClass        = 'PhanRedefinedExtendedClass';
    public const RedefinedClassReference       = 'PhanRedefinedClassReference';
    public const ReusedEnumCaseValue           = 'PhanReusedEnumCaseValue';

    // Issue::CATEGORY_ACCESS
    public const AccessPropertyPrivate     = 'PhanAccessPropertyPrivate';
    public const AccessPropertyProtected   = 'PhanAccessPropertyProtected';

    public const AccessReadOnlyProperty       = 'PhanAccessReadOnlyProperty';
    public const AccessWriteOnlyProperty      = 'PhanAccessWriteOnlyProperty';
    public const AccessReadOnlyMagicProperty  = 'PhanAccessReadOnlyMagicProperty';
    public const AccessWriteOnlyMagicProperty = 'PhanAccessWriteOnlyMagicProperty';

    public const AccessMethodPrivate       = 'PhanAccessMethodPrivate';
    public const AccessMethodPrivateWithCallMagicMethod = 'PhanAccessMethodPrivateWithCallMagicMethod';
    public const AccessMethodProtected     = 'PhanAccessMethodProtected';
    public const AccessMethodProtectedWithCallMagicMethod = 'PhanAccessMethodProtectedWithCallMagicMethod';
    public const AccessSignatureMismatch         = 'PhanAccessSignatureMismatch';
    public const AccessSignatureMismatchInternal = 'PhanAccessSignatureMismatchInternal';
    public const ConstructAccessSignatureMismatch = 'PhanConstructAccessSignatureMismatch';
    public const PropertyAccessSignatureMismatch = 'PhanPropertyAccessSignatureMismatch';
    public const PropertyAccessSignatureMismatchInternal  = 'PhanPropertyAccessSignatureMismatchInternal';
    public const ConstantAccessSignatureMismatch = 'PhanConstantAccessSignatureMismatch';
    public const ConstantAccessSignatureMismatchInternal  = 'PhanConstantAccessSignatureMismatchInternal';
    public const AccessStaticToNonStatic         = 'PhanAccessStaticToNonStatic';
    public const AccessNonStaticToStatic         = 'PhanAccessNonStaticToStatic';
    public const AccessStaticToNonStaticProperty = 'PhanAccessStaticToNonStaticProperty';
    public const AccessNonStaticToStaticProperty = 'PhanAccessNonStaticToStaticProperty';
    public const AccessClassConstantPrivate      = 'PhanAccessClassConstantPrivate';
    public const AccessClassConstantProtected    = 'PhanAccessClassConstantProtected';
    public const AccessClassConstantOfTraitDirectly = 'PhanAccessClassConstantOfTraitDirectly';
    public const AccessPropertyStaticAsNonStatic = 'PhanAccessPropertyStaticAsNonStatic';
    public const AccessPropertyNonStaticAsStatic = 'PhanAccessPropertyNonStaticAsStatic';
    public const AccessOwnConstructor            = 'PhanAccessOwnConstructor';

    public const AccessConstantInternal    = 'PhanAccessConstantInternal';
    public const AccessClassInternal       = 'PhanAccessClassInternal';
    public const AccessClassConstantInternal = 'PhanAccessClassConstantInternal';
    public const AccessPropertyInternal    = 'PhanAccessPropertyInternal';
    public const AccessMethodInternal      = 'PhanAccessMethodInternal';
    public const AccessWrongInheritanceCategory = 'PhanAccessWrongInheritanceCategory';
    public const AccessWrongInheritanceCategoryInternal = 'PhanAccessWrongInheritanceCategoryInternal';
    public const AccessExtendsFinalClass                = 'PhanAccessExtendsFinalClass';
    public const AccessExtendsFinalClassInternal        = 'PhanAccessExtendsFinalClassInternal';
    public const AccessOverridesFinalMethod             = 'PhanAccessOverridesFinalMethod';
    public const AccessOverridesFinalMethodInTrait      = 'PhanAccessOverridesFinalMethodInTrait';
    public const AccessOverridesFinalMethodInternal     = 'PhanAccessOverridesFinalMethodInternal';
    public const AccessOverridesFinalMethodPHPDoc       = 'PhanAccessOverridesFinalMethodPHPDoc';
    public const AccessOverridesFinalConstant           = 'PhanAccessOverridesFinalConstant';
    // TODO: Should probably also warn about the declaration
    public const AccessNonPublicAttribute               = 'PhanAccessNonPublicAttribute';

    // Issue::CATEGORY_COMPATIBLE
    public const CompatibleExpressionPHP7           = 'PhanCompatibleExpressionPHP7';
    public const CompatiblePHP7                     = 'PhanCompatiblePHP7';
    public const CompatibleNullableTypePHP70        = 'PhanCompatibleNullableTypePHP70';
    public const CompatibleShortArrayAssignPHP70    = 'PhanCompatibleShortArrayAssignPHP70';
    public const CompatibleKeyedArrayAssignPHP70    = 'PhanCompatibleKeyedArrayAssignPHP70';
    public const CompatibleVoidTypePHP70            = 'PhanCompatibleVoidTypePHP70';
    public const CompatibleNeverType                = 'PhanCompatibleNeverType';
    public const CompatibleTrueType                 = 'PhanCompatibleTrueType';
    public const CompatibleStandaloneType           = 'PhanCompatibleStandaloneType';
    public const CompatibleIterableTypePHP70        = 'PhanCompatibleIterableTypePHP70';
    public const CompatibleObjectTypePHP71          = 'PhanCompatibleObjectTypePHP71';
    public const CompatibleMixedType                = 'PhanCompatibleMixedType';
    public const CompatibleUseVoidPHP70             = 'PhanCompatibleUseVoidPHP70';
    public const CompatibleUseIterablePHP71         = 'PhanCompatibleUseIterablePHP71';
    public const CompatibleUseObjectPHP71           = 'PhanCompatibleUseObjectPHP71';
    public const CompatibleUseMixed                 = 'PhanCompatibleUseMixed';
    public const CompatibleMultiExceptionCatchPHP70 = 'PhanCompatibleMultiExceptionCatchPHP70';
    public const CompatibleNonCapturingCatch        = 'PhanCompatibleNonCapturingCatch';
    public const CompatibleNegativeStringOffset     = 'PhanCompatibleNegativeStringOffset';
    public const CompatibleAutoload                 = 'PhanCompatibleAutoload';
    public const CompatibleAssertDeclaration        = 'PhanCompatibleAssertDeclaration';
    public const CompatibleUnsetCast                = 'PhanCompatibleUnsetCast';
    public const CompatibleSyntaxNotice             = 'PhanCompatibleSyntaxNotice';
    public const CompatibleDimAlternativeSyntax     = 'PhanCompatibleDimAlternativeSyntax';
    public const CompatibleImplodeOrder             = 'PhanCompatibleImplodeOrder';
    public const CompatibleUnparenthesizedTernary   = 'PhanCompatibleUnparenthesizedTernary';
    public const CompatibleTypedProperty            = 'PhanCompatibleTypedProperty';
    public const CompatibleDefaultEqualsNull        = 'PhanCompatibleDefaultEqualsNull';
    public const CompatiblePHP8PHP4Constructor      = 'PhanCompatiblePHP8PHP4Constructor';
    public const CompatibleScalarTypePHP56          = 'PhanCompatibleScalarTypePHP56';
    public const CompatibleAnyReturnTypePHP56       = 'PhanCompatibleAnyReturnTypePHP56';
    public const CompatibleReadonlyProperty         = 'PhanCompatibleReadonlyProperty';
    public const CompatibleIntersectionType         = 'PhanCompatibleIntersectionType';
    public const CompatibleUnionType                = 'PhanCompatibleUnionType';
    public const CompatibleStaticType               = 'PhanCompatibleStaticType';
    public const CompatibleThrowExpression          = 'PhanCompatibleThrowExpression';
    public const CompatibleArrowFunction            = 'PhanCompatibleArrowFunction';
    public const CompatibleMatchExpression          = 'PhanCompatibleMatchExpression';
    public const CompatibleNullsafeOperator         = 'PhanCompatibleNullsafeOperator';
    public const CompatibleNamedArgument            = 'PhanCompatibleNamedArgument';
    public const CompatibleTrailingCommaArgumentList = 'PhanCompatibleTrailingCommaArgumentList';
    public const CompatibleTrailingCommaParameterList = 'PhanCompatibleTrailingCommaParameterList';
    public const CompatibleAttributeGroupOnSameLine      = 'PhanCompatibleAttributeGroupOnSameLine';
    public const CompatibleAttributeGroupOnMultipleLines = 'PhanCompatibleAttributeGroupOnMultipleLines';
    public const CompatibleConstructorPropertyPromotion  = 'PhanCompatibleConstructorPropertyPromotion';
    public const CompatibleSerializeInterfaceDeprecated  = 'PhanCompatibleSerializeInterfaceDeprecated';
    public const CompatibleFinalClassConstant  = 'PhanCompatibleFinalClassConstant';
    public const CompatibleAccessMethodOnTraitDefinition = 'PhanCompatibleAccessMethodOnTraitDefinition';
    public const CompatibleAccessPropertyOnTraitDefinition  = 'PhanCompatibleAccessPropertyOnTraitDefinition';
    public const CompatibleAbstractPrivateMethodInTrait    = 'PhanCompatibleAbstractPrivateMethodInTrait';
    public const CompatibleTraitConstant                 = 'PhanCompatibleTraitConstant';

    // Issue::CATEGORY_GENERIC
    public const TemplateTypeConstant       = 'PhanTemplateTypeConstant';
    public const TemplateTypeStaticMethod   = 'PhanTemplateTypeStaticMethod';
    public const TemplateTypeStaticProperty = 'PhanTemplateTypeStaticProperty';
    public const GenericGlobalVariable      = 'PhanGenericGlobalVariable';
    public const GenericConstructorTypes    = 'PhanGenericConstructorTypes';
    public const TemplateTypeNotUsedInFunctionReturn = 'PhanTemplateTypeNotUsedInFunctionReturn';
    public const TemplateTypeNotDeclaredInFunctionParams = 'PhanTemplateTypeNotDeclaredInFunctionParams';

    // Issue::CATEGORY_COMMENT
    public const DebugAnnotation                  = 'PhanDebugAnnotation';
    public const InvalidCommentForDeclarationType = 'PhanInvalidCommentForDeclarationType';
    public const MisspelledAnnotation             = 'PhanMisspelledAnnotation';
    public const UnextractableAnnotation          = 'PhanUnextractableAnnotation';
    public const UnextractableAnnotationPart      = 'PhanUnextractableAnnotationPart';
    public const UnextractableAnnotationSuffix    = 'PhanUnextractableAnnotationSuffix';
    public const UnextractableAnnotationElementName = 'PhanUnextractableAnnotationElementName';
    public const CommentParamWithoutRealParam     = 'PhanCommentParamWithoutRealParam';
    public const CommentParamAssertionWithoutRealParam = 'PhanCommentParamAssertionWithoutRealParam';
    public const CommentParamOnEmptyParamList     = 'PhanCommentParamOnEmptyParamList';
    public const CommentOverrideOnNonOverrideMethod = 'PhanCommentOverrideOnNonOverrideMethod';
    public const CommentOverrideOnNonOverrideConstant = 'PhanCommentOverrideOnNonOverrideConstant';
    public const CommentOverrideOnNonOverrideProperty = 'PhanCommentOverrideOnNonOverrideProperty';
    public const CommentAbstractOnInheritedConstant = 'PhanCommentAbstractOnInheritedConstant';
    public const CommentAbstractOnInheritedProperty = 'PhanCommentAbstractOnInheritedProperty';
    public const CommentAbstractOnInheritedMethod = 'PhanCommentAbstractOnInheritedMethod';
    public const CommentParamOutOfOrder           = 'PhanCommentParamOutOfOrder';
    public const CommentVarInsteadOfParam         = 'PhanCommentVarInsteadOfParam';
    public const ThrowTypeAbsent                  = 'PhanThrowTypeAbsent';
    public const ThrowTypeAbsentForCall           = 'PhanThrowTypeAbsentForCall';
    public const ThrowTypeMismatch                = 'PhanThrowTypeMismatch';
    public const ThrowTypeMismatchForCall         = 'PhanThrowTypeMismatchForCall';
    public const ThrowStatementInToString         = 'PhanThrowStatementInToString';
    public const ThrowCommentInToString           = 'PhanThrowCommentInToString';
    public const CommentAmbiguousClosure          = 'PhanCommentAmbiguousClosure';
    public const CommentDuplicateParam            = 'PhanCommentDuplicateParam';
    public const CommentDuplicateMagicMethod      = 'PhanCommentDuplicateMagicMethod';
    public const CommentDuplicateMagicProperty    = 'PhanCommentDuplicateMagicProperty';
    public const CommentObjectInClassConstantType = 'PhanCommentObjectInClassConstantType';
    public const CommentUnsupportedUnionType      = 'PhanCommentUnsupportedUnionType';
    public const CommentUnextractableTypeAlias    = 'PhanCommentUnextractableTypeAlias';
    public const TypeAliasUsedOutsideComment      = 'PhanTypeAliasUsedOutsideComment';
    public const TypeAliasInternalTypeConflict    = 'PhanTypeAliasInternalTypeConflict';
    // phpcs:enable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
    // end of issue name constants

    /** This category of issue is emitted when you're trying to access things that you can't access. */
    public const CATEGORY_ACCESS            = 1 << 1;
    /** This category will be emitted when Phan doesn't know how to analyze something. */
    public const CATEGORY_ANALYSIS          = 1 << 2;
    /** This category of issue is emitted when there are compatibility issues between PHP versions */
    public const CATEGORY_COMPATIBLE        = 1 << 3;
    /** This category of issue is for when you're doing stuff out of the context in which you're allowed to do it, e.g. referencing `self` or `parent` when not in a class, interface or trait. */
    public const CATEGORY_CONTEXT           = 1 << 4;
    /** This category of issue comes up when you're accessing deprecated elements (as marked by the `(at)deprecated` comment). */
    public const CATEGORY_DEPRECATED        = 1 << 5;
    /** Issues in this category are emitted when you have reasonable code but it isn't doing anything. */
    public const CATEGORY_NOOP              = 1 << 6;
    /** This category of error comes up when you're messing up your method or function parameters in some way. */
    public const CATEGORY_PARAMETER         = 1 << 7;
    /** This category of issue comes up when more than one thing of whatever type have the same name and namespace. */
    public const CATEGORY_REDEFINE          = 1 << 8;
    /** Static access to non-static methods, etc. */
    public const CATEGORY_STATIC            = 1 << 9;
    /** This category of issue come from using incorrect types or types that cannot cast to the expected types. */
    public const CATEGORY_TYPE              = 1 << 10;
    /** This category of issue comes up when there are references to undefined things. */
    public const CATEGORY_UNDEFINED         = 1 << 11;
    /** This category is for using non-variables where variables are expected. */
    public const CATEGORY_VARIABLE          = 1 << 12;
    /** This category is for plugins. */
    public const CATEGORY_PLUGIN            = 1 << 13;
    /** This category contains issues related to [Phan's generic type support](https://github.com/phan/phan/wiki/Generic-Types). */
    public const CATEGORY_GENERIC           = 1 << 14;
    /** This issue category comes up when there is an attempt to access an `(at)internal` element outside of the namespace in which it's defined. */
    public const CATEGORY_INTERNAL          = 1 << 15;
    /** This is emitted for some (but not all) comments which Phan thinks are invalid or unparsable. */
    public const CATEGORY_COMMENT           = 1 << 16;
    /** Emitted for syntax errors. */
    public const CATEGORY_SYNTAX            = 1 << 17;

    public const CATEGORY_NAME = [
        self::CATEGORY_ACCESS            => 'AccessError',
        self::CATEGORY_ANALYSIS          => 'Analysis',
        self::CATEGORY_COMMENT           => 'CommentError',
        self::CATEGORY_COMPATIBLE        => 'CompatError',
        self::CATEGORY_CONTEXT           => 'Context',
        self::CATEGORY_DEPRECATED        => 'DeprecatedError',
        self::CATEGORY_GENERIC           => 'Generic',
        self::CATEGORY_INTERNAL          => 'Internal',
        self::CATEGORY_NOOP              => 'NOOPError',
        self::CATEGORY_PARAMETER         => 'ParamError',
        self::CATEGORY_PLUGIN            => 'Plugin',
        self::CATEGORY_REDEFINE          => 'RedefineError',
        self::CATEGORY_STATIC            => 'StaticCallError',
        self::CATEGORY_SYNTAX            => 'Syntax',
        self::CATEGORY_TYPE              => 'TypeError',
        self::CATEGORY_UNDEFINED         => 'UndefError',
        self::CATEGORY_VARIABLE          => 'VarError',
    ];

    /** Low severity. E.g. documentation errors or code that would cause a (typically harmless) PHP notice. */
    public const SEVERITY_LOW      = 0;
    /** Normal severity. E.g. something that may cause a minor bug. */
    public const SEVERITY_NORMAL   = 5;
    /** Highest severity. Likely to cause an uncaught Error, Exception, or fatal error at runtime. */
    public const SEVERITY_CRITICAL = 10;

    // See https://docs.codeclimate.com/v1.0/docs/remediation
    // TODO: Decide on a way to estimate these and bring these up to date once codeclimate updates phan.
    // Right now, almost everything is REMEDIATION_B.
    public const REMEDIATION_A = 1000000;
    public const REMEDIATION_B = 3000000;
    /** @suppress PhanUnreferencedPublicClassConstant */
    public const REMEDIATION_C = 6000000;
    /** @suppress PhanUnreferencedPublicClassConstant */
    public const REMEDIATION_D = 12000000;
    /** @suppress PhanUnreferencedPublicClassConstant */
    public const REMEDIATION_E = 16000000;
    /** @suppress PhanUnreferencedPublicClassConstant */
    public const REMEDIATION_F = 18000000;

    // type id constants.
    public const TYPE_ID_UNKNOWN = 999;

    // Keep sorted and in sync with Colorizing::DEFAULT_COLOR_FOR_TEMPLATE
    public const UNCOLORED_FORMAT_STRING_FOR_TEMPLATE = [
        'CLASS'         => '%s',
        'CLASSLIKE'     => '%s',
        'CODE'          => '%s',  // A snippet from the code
        'COMMENT'       => '%s',  // contents of a phpdoc comment
        'CONST'         => '%s',
        'COUNT'         => '%d',
        'DETAILS'       => '%s',  // additional details about an error
        'ENUM'          => '%s',
        'FILE'          => '%s',
        'FUNCTIONLIKE'  => '%s',
        'FUNCTION'      => '%s',
        'INDEX'         => '%d',
        'INTERFACE'     => '%s',
        'ISSUETYPE'     => '%s',  // used by Phan\Output\Printer, for minor issues.
        'ISSUETYPE_CRITICAL' => '%s',  // for critical issues
        'ISSUETYPE_NORMAL' => '%s',  // for normal issues
        'LINE'          => '%d',
        'METHOD'        => '%s',
        'NAMESPACE'     => '%s',
        'OPERATOR'      => '%s',
        'PARAMETER'     => '%s',
        'PROPERTY'      => '%s',
        'SCALAR'        => '%s',  // A scalar from the code
        'STRING_LITERAL' => '%s',  // A string literal from the code
        'SUGGESTION'    => '%s',
        'TYPE'          => '%s',
        'TRAIT'         => '%s',
        'VARIABLE'      => '%s',
    ];

    /** @var string the type of this issue */
    private $type;

    /**
     * @var int (a preferably unique integer for $type, for the pylint output formatter)
     * Built in issue types must have a unique type id.
     */
    private $type_id;

    /** @var int the category of this issue (self::CATEGORY_*) */
    private $category;

    /** @var int the severity of this issue (self::SEVERITY_*) */
    private $severity;

    /** @var string The format string for this issue type. Contains a mix of {CLASS} and %s/%d annotations. Used for colorizing option. */
    private $template_raw;

    /** @var string The printf format string for this issue type. If --color is enabled, this will have unix color codes. */
    private $template;

    /** @var int the expected number of arguments to the format string $this->template */
    private $argument_count;

    /** @var int self::REMEDIATION_* */
    private $remediation_difficulty;

    /**
     * @param string $type the type of this issue
     * @param int $category the category of this issue (self::CATEGORY_*)
     * @param int $severity the severity of this issue (self::SEVERITY_*)
     * @param string $template_raw the template string for issue messages. Contains a mix of {CLASS} and %s/%d annotations.
     * @param int $remediation_difficulty self::REMEDIATION_*
     * @param int $type_id (unique integer id for $type)
     */
    public function __construct(
        string $type,
        int $category,
        int $severity,
        string $template_raw,
        int $remediation_difficulty,
        int $type_id
    ) {
        $this->type = $type;
        $this->category = $category;
        $this->severity = $severity;
        $this->template_raw = $template_raw;
        $this->template = self::templateToFormatString($template_raw);
        $this->remediation_difficulty = $remediation_difficulty;
        $this->type_id = $type_id;
    }

    /**
     * Converts the Phan template string to a regular format string.
     */
    public static function templateToFormatString(
        string $template
    ): string {
        /** @param list<string> $matches */
        return \preg_replace_callback('/{([A-Z_]+)}/', static function (array $matches) use ($template): string {
            $key = $matches[1];
            $replacement_exists = \array_key_exists($key, self::UNCOLORED_FORMAT_STRING_FOR_TEMPLATE);
            if (!$replacement_exists) {
                \error_log(\sprintf(
                    "No coloring info for issue message (%s), key {%s}. Valid template types: %s",
                    $template,
                    $key,
                    \implode(', ', \array_keys(self::UNCOLORED_FORMAT_STRING_FOR_TEMPLATE))
                ));
                return '%s';
            }
            return self::UNCOLORED_FORMAT_STRING_FOR_TEMPLATE[$key];
        }, $template);
    }

    /**
     * @return array<string,Issue>
     */
    public static function issueMap(): array
    {
        static $error_map;
        return $error_map ?? ($error_map = self::generateIssueMap());
    }

    /**
     * @return array<string,Issue>
     */
    private static function generateIssueMap(): array
    {
        // phpcs:disable Generic.Files.LineLength
        /**
         * @var list<Issue>
         * Note: All type ids should be unique, and be grouped by the category.
         * (E.g. If the category is (1 << x), then the type_id should be x*1000 + y
         * If new type ids are added, existing ones should not be changed.
         */
        $error_list = [
            // Issue::CATEGORY_SYNTAX
            new Issue(
                self::SyntaxError,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                "%s",
                self::REMEDIATION_A,
                17000
            ),
            new Issue(
                self::InvalidConstantExpression,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                "Constant expression contains invalid operations ({CODE})",
                self::REMEDIATION_A,
                17001
            ),
            new Issue(
                self::InvalidNode,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                "%s",
                self::REMEDIATION_A,
                17002
            ),
            new Issue(
                self::InvalidWriteToTemporaryExpression,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                "Cannot use temporary expression ({CODE} of type {TYPE}) in write context",
                self::REMEDIATION_A,
                17003
            ),
            new Issue(
                self::InvalidTraitUse,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                'Invalid trait use: {DETAILS}',
                self::REMEDIATION_A,
                17004
            ),
            // Could try to make a better suggestion, optionally
            new Issue(
                self::ContinueTargetingSwitch,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_NORMAL,
                '"continue" targeting switch is equivalent to "break". Did you mean to use "continue 2"?',
                self::REMEDIATION_A,
                17005
            ),
            new Issue(
                self::ContinueOrBreakNotInLoop,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                '\'{OPERATOR}\' not in the \'loop\' or \'switch\' context.',
                self::REMEDIATION_A,
                17006
            ),
            new Issue(
                self::ContinueOrBreakTooManyLevels,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                'Cannot \'{OPERATOR}\' {INDEX} levels.',
                self::REMEDIATION_A,
                17007
            ),
            new Issue(
                self::DuplicateUseNormal,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                "Cannot use {CLASSLIKE} as {CLASSLIKE} because the name is already in use",
                self::REMEDIATION_B,
                17008
            ),
            new Issue(
                self::DuplicateUseFunction,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                "Cannot use function {FUNCTION} as {FUNCTION} because the name is already in use",
                self::REMEDIATION_B,
                17009
            ),
            new Issue(
                self::DuplicateUseConstant,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                "Cannot use constant {CONST} as {CONST} because the name is already in use",
                self::REMEDIATION_B,
                17010
            ),
            new Issue(
                self::SyntaxCompileWarning,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_NORMAL,
                'Saw a warning while parsing: {DETAILS}',
                self::REMEDIATION_A,
                17011
            ),
            new Issue(
                self::SyntaxEmptyListArrayDestructuring,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                'Cannot use an empty list in the left hand side of an array destructuring operation',
                self::REMEDIATION_A,
                17012
            ),
            new Issue(
                self::SyntaxMixedKeyNoKeyArrayDestructuring,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                'Cannot mix keyed and unkeyed array entries in array destructuring assignments ({CODE})',
                self::REMEDIATION_A,
                17013
            ),
            new Issue(
                self::SyntaxReturnValueInVoid,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                'Syntax error: {TYPE} function {FUNCTIONLIKE} must not return a value (did you mean "{CODE}" instead of "{CODE}"?)',
                self::REMEDIATION_A,
                17014
            ),
            new Issue(
                self::SyntaxReturnStatementInNever,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                'Syntax error: function {FUNCTIONLIKE} has return type {TYPE}, meaning it must not contain return statements (it should exit, throw, or run forever)',
                self::REMEDIATION_A,
                17017
            ),
            new Issue(
                self::SyntaxReturnExpectedValue,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                'Syntax error: Function {FUNCTIONLIKE} with return type {TYPE} must return a value (did you mean "{CODE}" instead of "{CODE}"?)',
                self::REMEDIATION_A,
                17015
            ),
            new Issue(
                self::SyntaxEnumCaseExpectedValue,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                // XXX can't improve on this until the minimum supported AST extension version is raised due to php-ast not providing the actual flags until AST version 85.
                "Syntax error: Expected enum case {CONST} to have a value of type {TYPE} but it has no value",
                self::REMEDIATION_A,
                17016
            ),
            new Issue(
                self::SyntaxEnumCaseUnexpectedValue,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                // XXX can't improve on this until the minimum supported AST extension version is raised due to php-ast not providing the actual flags until AST version 85.
                "Syntax error: Expected enum case {CONST} not to have a value",
                self::REMEDIATION_A,
                17020
            ),
            new Issue(
                self::PrivateFinalConstant,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                "Private constant is not allowed to be final",
                self::REMEDIATION_A,
                17018
            ),
            new Issue(
                self::ReadonlyPropertyMissingType,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_CRITICAL,
                'Readonly property ${PROPERTY} must have a declared type',
                self::REMEDIATION_A,
                17021
            ),
            new Issue(
                self::PrivateFinalMethod,
                self::CATEGORY_SYNTAX,
                self::SEVERITY_NORMAL,
                "PHP warns about private method {METHOD} being final starting in php 8.0",
                self::REMEDIATION_A,
                17019
            ),

            // Issue::CATEGORY_UNDEFINED
            new Issue(
                self::EmptyFile,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_LOW,
                "Empty file {FILE}",
                self::REMEDIATION_B,
                11000
            ),
            new Issue(
                self::MissingRequireFile,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Missing required file {FILE}",
                self::REMEDIATION_B,
                11040
            ),
            new Issue(
                self::InvalidRequireFile,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Required file {FILE} is not a file",
                self::REMEDIATION_B,
                11041
            ),
            new Issue(
                self::ParentlessClass,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Reference to parent of class {CLASS} that does not extend anything",
                self::REMEDIATION_B,
                11001
            ),
            new Issue(
                self::UndeclaredClass,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Reference to undeclared class {CLASS}",
                self::REMEDIATION_B,
                11002
            ),
            new Issue(
                self::UndeclaredExtendedClass,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Class extends undeclared class {CLASS}",
                self::REMEDIATION_B,
                11003
            ),
            new Issue(
                self::UndeclaredInterface,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Class implements undeclared interface {INTERFACE}",
                self::REMEDIATION_B,
                11004
            ),
            new Issue(
                self::UndeclaredTrait,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Class uses undeclared trait {TRAIT}",
                self::REMEDIATION_B,
                11005
            ),
            new Issue(
                self::UndeclaredClassCatch,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Catching undeclared class {CLASS}",
                self::REMEDIATION_B,
                11006
            ),
            new Issue(
                self::UndeclaredClassConstant,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Reference to constant {CONST} from undeclared class {CLASS}",
                self::REMEDIATION_B,
                11007
            ),
            new Issue(
                self::UndeclaredClassInstanceof,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Checking instanceof against undeclared class {CLASS}",
                self::REMEDIATION_B,
                11008
            ),
            new Issue(
                self::UndeclaredClassMethod,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Call to method {METHOD} from undeclared class {CLASS}",
                self::REMEDIATION_B,
                11009
            ),
            new Issue(
                self::UndeclaredClassProperty,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Reference to instance property {PROPERTY} from undeclared class {CLASS}",
                self::REMEDIATION_B,
                11038
            ),
            new Issue(
                self::UndeclaredClassStaticProperty,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Reference to static property {PROPERTY} from undeclared class {CLASS}",
                self::REMEDIATION_B,
                11039
            ),
            new Issue(
                self::UndeclaredClassReference,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Reference to undeclared class {CLASS}",
                self::REMEDIATION_B,
                11010
            ),
            new Issue(
                self::UndeclaredConstant,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Reference to undeclared constant {CONST}. This will cause a thrown Error in php 8.0+.",
                self::REMEDIATION_B,
                11011
            ),
            new Issue(
                self::UndeclaredConstantOfClass,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Reference to undeclared class constant {CONST}",
                self::REMEDIATION_B,
                11053
            ),
            new Issue(
                self::UndeclaredFunction,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Call to undeclared function {FUNCTION}",
                self::REMEDIATION_B,
                11012
            ),
            new Issue(
                self::UndeclaredMethod,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Call to undeclared method {METHOD}",
                self::REMEDIATION_B,
                11013
            ),
            new Issue(
                self::PossiblyUndeclaredMethod,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Call to possibly undeclared method {METHOD} on type {TYPE} ({TYPE} does not declare the method)",
                self::REMEDIATION_B,
                11049
            ),
            new Issue(
                self::UndeclaredStaticMethod,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Static call to undeclared method {METHOD}",
                self::REMEDIATION_B,
                11014
            ),
            new Issue(
                self::UndeclaredProperty,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Reference to undeclared property {PROPERTY}",
                self::REMEDIATION_B,
                11015
            ),
            new Issue(
                self::PossiblyUndeclaredProperty,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Reference to possibly undeclared property {PROPERTY} of expression of type {TYPE} ({TYPE} does not declare that property)",
                self::REMEDIATION_B,
                11050
            ),
            new Issue(
                self::PossiblyUndeclaredPropertyOfClass,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Reference to possibly undeclared property {PROPERTY} of expression of type {TYPE} (instances of {CLASS} do not declare that property)",
                self::REMEDIATION_B,
                11056
            ),
            new Issue(
                self::UndeclaredStaticProperty,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Static property '{PROPERTY}' on {CLASS} is undeclared",
                self::REMEDIATION_B,
                11016
            ),
            new Issue(
                self::TraitParentReference,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_LOW,
                "Reference to parent from trait {TRAIT}",
                self::REMEDIATION_B,
                11017
            ),
            new Issue(
                self::UndeclaredVariable,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Variable \${VARIABLE} is undeclared",
                self::REMEDIATION_B,
                11018
            ),
            new Issue(
                self::PossiblyUndeclaredVariable,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Variable \${VARIABLE} is possibly undeclared",
                self::REMEDIATION_B,
                11051
            ),
            new Issue(
                self::UndeclaredThis,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Variable \${VARIABLE} is undeclared",
                self::REMEDIATION_B,
                11046
            ),
            new Issue(
                self::UndeclaredGlobalVariable,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Global variable \${VARIABLE} is undeclared",
                self::REMEDIATION_B,
                11047
            ),
            new Issue(
                self::PossiblyUndeclaredGlobalVariable,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Global variable \${VARIABLE} is possibly undeclared",
                self::REMEDIATION_B,
                11052
            ),
            new Issue(
                self::UndeclaredTypeParameter,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Parameter \${PARAMETER} has undeclared type {TYPE}",
                self::REMEDIATION_B,
                11019
            ),
            new Issue(
                self::UndeclaredTypeProperty,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Property {PROPERTY} has undeclared type {TYPE}",
                self::REMEDIATION_B,
                11020
            ),
            new Issue(
                self::UndeclaredTypeClassConstant,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_LOW,
                "Class constant {CONST} has undeclared class type {TYPE}",
                self::REMEDIATION_B,
                11054
            ),
            new Issue(
                self::UndeclaredClosureScope,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Reference to undeclared class {CLASS} in @phan-closure-scope",
                self::REMEDIATION_B,
                11021
            ),
            new Issue(
                self::ClassContainsAbstractMethod,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "non-abstract class {CLASS} contains abstract method {METHOD} declared at {FILE}:{LINE}",
                self::REMEDIATION_B,
                11022
            ),
            new Issue(
                self::ClassContainsAbstractMethodInternal,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "non-abstract class {CLASS} contains abstract internal method {METHOD}",
                self::REMEDIATION_B,
                11023
            ),
            new Issue(
                self::UndeclaredAliasedMethodOfTrait,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Alias {METHOD} was defined for a method {METHOD} which does not exist in trait {TRAIT}",
                self::REMEDIATION_B,
                11024
            ),
            new Issue(
                self::RequiredTraitNotAdded,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Required trait {TRAIT} for trait adaptation was not added to class",
                self::REMEDIATION_B,
                11025
            ),
            new Issue(
                self::AmbiguousTraitAliasSource,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Trait alias {METHOD} has an ambiguous source method {METHOD} with more than one possible source trait. Possibilities: {TRAIT}",
                self::REMEDIATION_B,
                11026
            ),
            new Issue(
                self::UndeclaredVariableDim,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_LOW,
                "Variable \${VARIABLE} was undeclared, but array fields are being added to it.",
                self::REMEDIATION_B,
                11027
            ),
            new Issue(
                self::UndeclaredVariableAssignOp,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_LOW,
                "Variable \${VARIABLE} was undeclared, but it is being used as the left-hand side of an assignment operation",
                self::REMEDIATION_B,
                11037
            ),
            new Issue(
                self::UndeclaredTypeReturnType,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Return type of {METHOD} is undeclared type {TYPE}",
                self::REMEDIATION_B,
                11028
            ),
            new Issue(
                self::UndeclaredTypeThrowsType,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "@throws type of {METHOD} has undeclared type {TYPE}",
                self::REMEDIATION_B,
                11034
            ),
            new Issue(
                self::UndeclaredClassAliasOriginal,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_CRITICAL,
                "Reference to undeclared class {CLASS} for the original class of a class_alias for {CLASS}",
                self::REMEDIATION_B,
                11029
            ),
            new Issue(
                self::UndeclaredClassInCallable,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Reference to undeclared class {CLASS} in callable {METHOD}",
                self::REMEDIATION_B,
                11030
            ),
            new Issue(
                self::UndeclaredStaticMethodInCallable,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Reference to undeclared static method {METHOD} in callable",
                self::REMEDIATION_B,
                11031
            ),
            new Issue(
                self::UndeclaredFunctionInCallable,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Call to undeclared function {FUNCTION} in callable",
                self::REMEDIATION_B,
                11032
            ),
            new Issue(
                self::UndeclaredMethodInCallable,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Call to undeclared method {METHOD} in callable. Possible object type(s) for that method are {TYPE}",
                self::REMEDIATION_B,
                11033
            ),
            new Issue(
                self::EmptyFQSENInCallable,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Possible call to a function '{FUNCTIONLIKE}' with an empty FQSEN.",
                self::REMEDIATION_B,
                11035
            ),
            new Issue(
                self::EmptyFQSENInClasslike,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Possible use of a classlike '{CLASSLIKE}' with an empty FQSEN.",
                self::REMEDIATION_B,
                11036
            ),
            new Issue(
                self::InvalidFQSENInCallable,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Possible call to a function '{FUNCTIONLIKE}' with an invalid FQSEN.",
                self::REMEDIATION_B,
                11042
            ),
            new Issue(
                self::InvalidFQSENInClasslike,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_NORMAL,
                "Possible use of a classlike '{CLASSLIKE}' with an invalid FQSEN.",
                self::REMEDIATION_B,
                11043
            ),
            new Issue(
                self::UndeclaredMagicConstant,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_LOW,
                "Reference to magic constant {CONST} that is undeclared in the current scope: {DETAILS}",
                self::REMEDIATION_B,
                11044
            ),
            new Issue(
                self::UndeclaredInvokeInCallable,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_LOW,
                "Possible attempt to access missing magic method {FUNCTIONLIKE} of '{CLASS}'",
                self::REMEDIATION_B,
                11045
            ),
            new Issue(
                self::PossiblyUnsetPropertyOfThis,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_LOW,
                'Attempting to read property {PROPERTY} which was unset in the current scope',
                self::REMEDIATION_B,
                11048
            ),
            new Issue(
                self::UndeclaredClassAttribute,
                self::CATEGORY_UNDEFINED,
                self::SEVERITY_LOW,
                "Reference to undeclared class {CLASS} in an attribute",
                self::REMEDIATION_B,
                11055
            ),

            // Issue::CATEGORY_ANALYSIS
            new Issue(
                self::Unanalyzable,
                self::CATEGORY_ANALYSIS,
                self::SEVERITY_LOW,
                "Expression is unanalyzable or feature is unimplemented. Please create an issue at https://github.com/phan/phan/issues/new.",
                self::REMEDIATION_B,
                2000
            ),
            new Issue(
                self::UnanalyzableInheritance,
                self::CATEGORY_ANALYSIS,
                self::SEVERITY_LOW,
                "Unable to determine the method(s) which {METHOD} overrides, but Phan inferred that it did override something earlier. Please create an issue at https://github.com/phan/phan/issues/new with a test case.",
                self::REMEDIATION_B,
                2001
            ),
            new Issue(
                self::InvalidConstantFQSEN,
                self::CATEGORY_ANALYSIS,
                self::SEVERITY_NORMAL,
                "'{CONST}' is an invalid FQSEN for a constant",
                self::REMEDIATION_B,
                2002
            ),
            new Issue(
                self::ReservedConstantName,
                self::CATEGORY_ANALYSIS,
                self::SEVERITY_NORMAL,
                "'{CONST}' has a reserved keyword in the constant name",
                self::REMEDIATION_B,
                2003
            ),

            // Issue::CATEGORY_TYPE
            new Issue(
                self::TypeMismatchProperty,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Assigning {CODE} of type {TYPE} to property but {PROPERTY} is {TYPE}",
                self::REMEDIATION_B,
                10001
            ),
            new Issue(
                self::PartialTypeMismatchProperty,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Assigning {CODE} of type {TYPE} to property but {PROPERTY} is {TYPE} ({TYPE} is incompatible)",
                self::REMEDIATION_B,
                10063
            ),
            new Issue(
                self::PossiblyNullTypeMismatchProperty,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Assigning {CODE} of type {TYPE} to property but {PROPERTY} is {TYPE} ({TYPE} is incompatible)",
                self::REMEDIATION_B,
                10064
            ),
            new Issue(
                self::PossiblyFalseTypeMismatchProperty,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Assigning {CODE} of type {TYPE} to property but {PROPERTY} is {TYPE} ({TYPE} is incompatible)",
                self::REMEDIATION_B,
                10065
            ),
            new Issue(
                self::TypeMismatchDefault,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Default value for {TYPE} \${PARAMETER} can't be {TYPE}",
                self::REMEDIATION_B,
                10002
            ),
            new Issue(
                self::TypeMismatchDefaultIntersection,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Default value for {TYPE} \${PARAMETER} can't be {TYPE} because the parameter contains intersection types",
                self::REMEDIATION_B,
                10185
            ),
            new Issue(
                self::TypeMismatchVariadicComment,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "{PARAMETER} is variadic in comment, but not variadic in param ({PARAMETER})",
                self::REMEDIATION_B,
                10021
            ),
            new Issue(
                self::TypeMismatchVariadicParam,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                '{PARAMETER} is not variadic in comment, but variadic in param ({PARAMETER})',
                self::REMEDIATION_B,
                10023
            ),
            new Issue(
                self::TypeMismatchArgument,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE} but {FUNCTIONLIKE} takes {TYPE} defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                10003
            ),
            new Issue(
                self::TypeMismatchArgumentProbablyReal,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE}{DETAILS} but {FUNCTIONLIKE} takes {TYPE}{DETAILS} defined at {FILE}:{LINE} (the inferred real argument type has nothing in common with the parameter\'s phpdoc type)',
                self::REMEDIATION_B,
                10166
            ),
            new Issue(
                self::TypeMismatchArgumentReal,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE}{DETAILS} but {FUNCTIONLIKE} takes {TYPE}{DETAILS} defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                10140
            ),
            new Issue(
                self::TypeMismatchArgumentNullable,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE} but {FUNCTIONLIKE} takes {TYPE} defined at {FILE}:{LINE} (expected type to be non-nullable)',
                self::REMEDIATION_B,
                10105
            ),
            new Issue(
                self::TypeMismatchArgumentSuperType,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE} but {FUNCTIONLIKE} takes {TYPE} defined at {FILE}:{LINE} (expected type to be the same or a subtype, but saw a supertype instead)',
                self::REMEDIATION_B,
                10186
            ),
            new Issue(
                self::TypeMismatchArgumentInternal,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE} but {FUNCTIONLIKE} takes {TYPE}',
                self::REMEDIATION_B,
                10004
            ),
            new Issue(
                self::TypeMismatchArgumentInternalReal,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE}{DETAILS} but {FUNCTIONLIKE} takes {TYPE}{DETAILS}',
                self::REMEDIATION_B,
                10139
            ),
            new Issue(
                self::TypeMismatchArgumentInternalProbablyReal,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE}{DETAILS} but {FUNCTIONLIKE} takes {TYPE}{DETAILS}',
                self::REMEDIATION_B,
                10148
            ),
            new Issue(
                self::TypeMismatchArgumentNullableInternal,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE} but {FUNCTIONLIKE} takes {TYPE} (expected type to be non-nullable)',
                self::REMEDIATION_B,
                10106
            ),
            new Issue(
                self::TypeMismatchArgumentPropertyReference,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} is property {PROPERTY} with type {TYPE} but {FUNCTIONLIKE} takes a reference of type {TYPE}',
                self::REMEDIATION_B,
                10141
            ),
            new Issue(
                self::TypeMismatchArgumentPropertyReferenceReal,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} is property {PROPERTY} with type {TYPE}{DETAILS} but {FUNCTIONLIKE} takes a reference of type {TYPE}{DETAILS}',
                self::REMEDIATION_B,
                10142
            ),
            new Issue(
                self::TypeMismatchGeneratorYieldValue,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Yield statement has a value {CODE} with type {TYPE} but {FUNCTIONLIKE} is declared to yield values of type {TYPE} in {TYPE}",
                self::REMEDIATION_B,
                10067
            ),
            new Issue(
                self::TypeMismatchGeneratorYieldKey,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Yield statement has a key {CODE} with type {TYPE} but {FUNCTIONLIKE} is declared to yield keys of type {TYPE} in {TYPE}",
                self::REMEDIATION_B,
                10068
            ),
            new Issue(
                self::TypeInvalidYieldFrom,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Yield from statement was passed an invalid expression {CODE} of type {TYPE} (expected Traversable/array)",
                self::REMEDIATION_B,
                10069
            ),
            new Issue(
                self::PartialTypeMismatchArgument,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE} but {FUNCTIONLIKE} takes {TYPE} ({TYPE} is incompatible) defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                10054
            ),
            new Issue(
                self::PartialTypeMismatchArgumentInternal,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE} but {FUNCTIONLIKE} takes {TYPE} ({TYPE} is incompatible)',
                self::REMEDIATION_B,
                10055
            ),
            new Issue(
                self::PossiblyNullTypeArgument,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE} but {FUNCTIONLIKE} takes {TYPE} ({TYPE} is incompatible) defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                10056
            ),
            new Issue(
                self::PossiblyNullTypeArgumentInternal,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE} but {FUNCTIONLIKE} takes {TYPE} ({TYPE} is incompatible)',
                self::REMEDIATION_B,
                10057
            ),
            new Issue(
                self::PossiblyFalseTypeArgument,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE} but {FUNCTIONLIKE} takes {TYPE} ({TYPE} is incompatible) defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                10058
            ),
            new Issue(
                self::PossiblyFalseTypeArgumentInternal,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} (${PARAMETER}) is {CODE} of type {TYPE} but {FUNCTIONLIKE} takes {TYPE} ({TYPE} is incompatible)',
                self::REMEDIATION_B,
                10059
            ),
            new Issue(
                self::TypeMismatchReturn,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Returning {CODE} of type {TYPE} but {FUNCTIONLIKE} is declared to return {TYPE}",
                self::REMEDIATION_B,
                10005
            ),
            new Issue(
                self::TypeMismatchReturnNullable,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Returning {CODE} of type {TYPE} but {FUNCTIONLIKE} is declared to return {TYPE} (expected returned value to be non-nullable)",
                self::REMEDIATION_B,
                10107
            ),
            new Issue(
                self::TypeMismatchReturnReal,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Returning {CODE} of type {TYPE}{DETAILS} but {FUNCTIONLIKE} is declared to return {TYPE}{DETAILS}",
                self::REMEDIATION_B,
                10138
            ),
            new Issue(
                self::TypeMismatchReturnProbablyReal,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Returning {CODE} of type {TYPE}{DETAILS} but {FUNCTIONLIKE} is declared to return {TYPE}{DETAILS} (the inferred real return type has nothing in common with the declared phpdoc return type)",
                self::REMEDIATION_B,
                10167
            ),
            new Issue(
                self::PartialTypeMismatchReturn,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Returning {CODE} of type {TYPE} but {FUNCTIONLIKE} is declared to return {TYPE} ({TYPE} is incompatible)",
                self::REMEDIATION_B,
                10060
            ),
            new Issue(
                self::TypeMismatchReturnSuperType,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Returning {CODE} of type {TYPE} but {FUNCTIONLIKE} is declared to return {TYPE} (saw a supertype instead of a subtype)",
                self::REMEDIATION_B,
                10176
            ),
            new Issue(
                self::PossiblyNullTypeReturn,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Returning {CODE} of type {TYPE} but {FUNCTIONLIKE} is declared to return {TYPE} ({TYPE} is incompatible)",
                self::REMEDIATION_B,
                10061
            ),
            new Issue(
                self::PossiblyFalseTypeReturn,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Returning {CODE} of type {TYPE} but {FUNCTIONLIKE} is declared to return {TYPE} ({TYPE} is incompatible)",
                self::REMEDIATION_B,
                10062
            ),
            new Issue(
                self::TypeMismatchDeclaredReturn,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Doc-block of {METHOD} contains declared return type {TYPE} which is incompatible with the return type {TYPE} declared in the signature",
                self::REMEDIATION_B,
                10020
            ),
            new Issue(
                self::TypeMismatchDeclaredReturnNullable,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Doc-block of {METHOD} has declared return type {TYPE} which is not a permitted replacement of the nullable return type {TYPE} declared in the signature ('?T' should be documented as 'T|null' or '?T')",
                self::REMEDIATION_B,
                10028
            ),
            new Issue(
                self::TypeMismatchDeclaredParam,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Doc-block of \${PARAMETER} in {METHOD} contains phpdoc param type {TYPE} which is incompatible with the param type {TYPE} declared in the signature",
                self::REMEDIATION_B,
                10022
            ),
            new Issue(
                self::TypeMismatchDeclaredParamNullable,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Doc-block of \${PARAMETER} in {METHOD} is phpdoc param type {TYPE} which is not a permitted replacement of the nullable param type {TYPE} declared in the signature ('?T' should be documented as 'T|null' or '?T')",
                self::REMEDIATION_B,
                10027
            ),
            new Issue(
                self::TypeMissingReturn,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Method {METHOD} is declared to return {TYPE} in phpdoc but has no return value",
                self::REMEDIATION_B,
                10006
            ),
            new Issue(
                self::TypeMissingReturnReal,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Method {METHOD} is declared to return {TYPE} in its real type signature but has no return value",
                self::REMEDIATION_B,
                10157
            ),
            new Issue(
                self::TypeMismatchForeach,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "{TYPE} passed to foreach instead of array",
                self::REMEDIATION_B,
                10007
            ),
            new Issue(
                self::TypeArrayOperator,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid array operand provided to operator '{OPERATOR}' between types {TYPE} and {TYPE}",
                self::REMEDIATION_B,
                10008
            ),
            new Issue(
                self::TypeArraySuspicious,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Suspicious array access to {CODE} of type {TYPE}",
                self::REMEDIATION_B,
                10009
            ),
            new Issue(
                self::TypeArrayUnsetSuspicious,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Suspicious attempt to unset an offset of a value {CODE} of type {TYPE}",
                self::REMEDIATION_B,
                10048
            ),
            new Issue(
                self::TypeComparisonToArray,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "{TYPE} to array comparison",
                self::REMEDIATION_B,
                10010
            ),
            new Issue(
                self::TypeComparisonFromArray,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "array to {TYPE} comparison",
                self::REMEDIATION_B,
                10011
            ),
            new Issue(
                self::TypeConversionFromArray,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "array to {TYPE} conversion",
                self::REMEDIATION_B,
                10012
            ),
            new Issue(
                self::TypeInstantiateAbstract,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Instantiation of abstract class {CLASS}",
                self::REMEDIATION_B,
                10013
            ),
            new Issue(
                self::TypeInstantiateAbstractStatic,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Potential instantiation of abstract class {CLASS} (not an issue if this method is only called from a non-abstract subclass)",
                self::REMEDIATION_B,
                10111
            ),
            new Issue(
                self::TypeInstantiateEnum,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Saw instantiation of enum {ENUM}",
                self::REMEDIATION_B,
                10174
            ),
            new Issue(
                self::TypeInstantiateInterface,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Instantiation of interface {INTERFACE}",
                self::REMEDIATION_B,
                10014
            ),
            new Issue(
                self::TypeInstantiateTrait,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Instantiation of trait {TRAIT}",
                self::REMEDIATION_B,
                10074
            ),
            new Issue(
                self::TypeInstantiateTraitStaticOrSelf,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Potential instantiation of trait {TRAIT} (not an issue if this method is only called from a non-abstract class using the trait)",
                self::REMEDIATION_B,
                10112
            ),
            new Issue(
                self::TypeInvalidClosureScope,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid @phan-closure-scope: expected a class name, got {TYPE}",
                self::REMEDIATION_B,
                10024
            ),
            new Issue(
                self::TypeInvalidRightOperand,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid operator: left operand is array and right is not",
                self::REMEDIATION_B,
                10015
            ),
            new Issue(
                self::TypeInvalidLeftOperand,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid operator: right operand is array and left is not",
                self::REMEDIATION_B,
                10016
            ),
            new Issue(
                self::TypeInvalidRightOperandOfAdd,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid operator: right operand of {OPERATOR} is {TYPE} (expected array or number)",
                self::REMEDIATION_B,
                10070
            ),
            new Issue(
                self::TypeInvalidLeftOperandOfAdd,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid operator: left operand of {OPERATOR} is {TYPE} (expected array or number)",
                self::REMEDIATION_B,
                10071
            ),
            new Issue(
                self::TypeInvalidRightOperandOfNumericOp,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid operator: right operand of {OPERATOR} is {TYPE} (expected number)",
                self::REMEDIATION_B,
                10072
            ),
            new Issue(
                self::TypeInvalidLeftOperandOfNumericOp,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid operator: left operand of {OPERATOR} is {TYPE} (expected number)",
                self::REMEDIATION_B,
                10073
            ),
            new Issue(
                self::TypeInvalidRightOperandOfIntegerOp,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid operator: right operand of {OPERATOR} is {TYPE} (expected int)",
                self::REMEDIATION_B,
                10100
            ),
            new Issue(
                self::TypeInvalidLeftOperandOfIntegerOp,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid operator: left operand of {OPERATOR} is {TYPE} (expected int)",
                self::REMEDIATION_B,
                10101
            ),
            new Issue(
                self::TypeInvalidRightOperandOfBitwiseOp,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid operator: right operand of {OPERATOR} is {TYPE} (expected int|string)",
                self::REMEDIATION_B,
                10163
            ),
            new Issue(
                self::TypeInvalidLeftOperandOfBitwiseOp,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid operator: left operand of {OPERATOR} is {TYPE} (expected int|string)",
                self::REMEDIATION_B,
                10164
            ),
            new Issue(
                self::TypeInvalidUnaryOperandNumeric,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid operator: unary operand of {STRING_LITERAL} is {TYPE} (expected number)",
                self::REMEDIATION_B,
                10075
            ),
            new Issue(
                self::TypeInvalidUnaryOperandBitwiseNot,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid operator: unary operand of {STRING_LITERAL} is {TYPE} (expected number that can fit in an int, or string)",
                self::REMEDIATION_B,
                10076
            ),
            new Issue(
                self::TypeParentConstructorCalled,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Must call parent::__construct() from {CLASS} which extends {CLASS}",
                self::REMEDIATION_B,
                10017
            ),
            new Issue(
                self::TypeNonVarPassByRef,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Only variables can be passed by reference at argument {INDEX} of {FUNCTIONLIKE}",
                self::REMEDIATION_B,
                10018
            ),
            new Issue(
                self::TypeNonVarReturnByRef,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Only variables can be returned by reference in {FUNCTIONLIKE}",
                self::REMEDIATION_B,
                10144
            ),
            new Issue(
                self::NonClassMethodCall,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Call to method {METHOD} on non-class type {TYPE}",
                self::REMEDIATION_B,
                10019
            ),
            new Issue(
                self::TypeVoidAssignment,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Cannot assign void return value",
                self::REMEDIATION_B,
                10000
            ),
            new Issue(
                self::TypeVoidArgument,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Cannot use void return value {CODE} as a function argument",
                self::REMEDIATION_B,
                10161
            ),
            new Issue(
                self::TypeVoidExpression,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Suspicious use of void return value {CODE} where a value is expected",
                self::REMEDIATION_B,
                10162
            ),
            new Issue(
                self::TypeSuspiciousIndirectVariable,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Indirect variable ${(expr)} has invalid inner expression type {TYPE}, expected string/integer',
                self::REMEDIATION_B,
                10025
            ),
            new Issue(
                self::TypeMagicVoidWithReturn,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                'Found a return statement with a value in the implementation of the magic method {METHOD}, expected void return type',
                self::REMEDIATION_B,
                10026
            ),
            new Issue(
                self::TypeInvalidInstanceof,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Found an instanceof class name {CODE} of type {TYPE}, but class name must be a valid object or a string',
                self::REMEDIATION_B,
                10029
            ),
            new Issue(
                self::TypeMismatchDimAssignment,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'When appending to a value of type {TYPE}, found an array access index of type {TYPE}, but expected the index to be of type {TYPE}',
                self::REMEDIATION_B,
                10030
            ),
            new Issue(
                self::TypeMismatchDimEmpty,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                'Assigning to an empty array index of a value of type {TYPE}, but expected the index to exist and be of type {TYPE}',
                self::REMEDIATION_B,
                10031
            ),
            new Issue(
                self::TypeMismatchDimFetch,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'When fetching an array index from a value of type {TYPE}, found an array index of type {TYPE}, but expected the index to be of type {TYPE}',
                self::REMEDIATION_B,
                10032
            ),
            new Issue(
                self::TypeMismatchDimFetchNullable,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'When fetching an array index from a value of type {TYPE}, found an array index of type {TYPE}, but expected the index to be of the non-nullable type {TYPE}',
                self::REMEDIATION_B,
                10044
            ),
            new Issue(
                self::TypeInvalidCallableArraySize,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'In a place where phan was expecting a callable, saw an array of size {COUNT}, but callable arrays must be of size 2',
                self::REMEDIATION_B,
                10033
            ),
            new Issue(
                self::TypeInvalidCallableArrayKey,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'In a place where phan was expecting a callable, saw an array with an unexpected key for element #{INDEX} (expected [$class_or_expr, $method_name])',
                self::REMEDIATION_B,
                10034
            ),
            new Issue(
                self::TypeInvalidCallableObjectOfMethod,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'In a place where phan was expecting a callable, saw a two-element array with a class or expression with an unexpected type {TYPE} (expected a class type or string). Method name was {METHOD}',
                self::REMEDIATION_B,
                10035
            ),
            new Issue(
                self::TypeExpectedObject,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Expected an object instance but saw expression {CODE} with type {TYPE}',
                self::REMEDIATION_B,
                10036
            ),
            new Issue(
                self::TypeExpectedObjectOrClassName,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Expected an object instance or the name of a class but saw expression {CODE} with type {TYPE}',
                self::REMEDIATION_B,
                10037
            ),
            new Issue(
                self::TypeExpectedObjectPropAccess,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                'Expected an object instance when accessing an instance property, but saw an expression {CODE} with type {TYPE}',
                self::REMEDIATION_B,
                10038
            ),
            new Issue(
                self::TypeExpectedObjectStaticPropAccess,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Expected an object instance or a class name when accessing a static property, but saw an expression {CODE} with type {TYPE}',
                self::REMEDIATION_B,
                10039
            ),
            new Issue(
                self::TypeExpectedObjectPropAccessButGotNull,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Expected an object instance when accessing an instance property, but saw an expression {CODE} with type {TYPE}',
                self::REMEDIATION_B,
                10040
            ),
            new Issue(
                self::TypeMismatchUnpackKey,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'When unpacking a value of type {TYPE}, the value\'s keys were of type {TYPE}, but the keys should be consecutive integers starting from 0',
                self::REMEDIATION_B,
                10041
            ),
            new Issue(
                self::TypeMismatchUnpackKeyArraySpread,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'When unpacking a value of type {TYPE}, the value\'s keys were of type {TYPE}, but the keys should be integers before PHP 8.1',
                self::REMEDIATION_B,
                10109
            ),
            new Issue(
                self::TypeMismatchUnpackValue,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Attempting to unpack a value of type {TYPE} which does not contain any subtypes of iterable (such as array or Traversable)',
                self::REMEDIATION_B,
                10042
            ),
            new Issue(
                self::TypeMismatchArrayDestructuringKey,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Attempting an array destructing assignment with a key of type {TYPE} but the only key types of the right-hand side are of type {TYPE}',
                self::REMEDIATION_B,
                10043
            ),
            new Issue(
                self::TypeArraySuspiciousNullable,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Suspicious array access to {CODE} of nullable type {TYPE}",
                self::REMEDIATION_B,
                10045
            ),
            new Issue(
                self::TypeArraySuspiciousNull,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Suspicious array access to {CODE} of type null",
                self::REMEDIATION_B,
                10136
            ),
            new Issue(
                self::TypeInvalidDimOffset,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid offset {SCALAR} of {CODE} of array type {TYPE}",
                self::REMEDIATION_B,
                10046
            ),
            new Issue(
                self::TypePossiblyInvalidDimOffset,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Possibly invalid offset {SCALAR} of {CODE} of array type {TYPE}",
                self::REMEDIATION_B,
                10154
            ),
            new Issue(
                self::TypeInvalidDimOffsetArrayDestructuring,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid offset {SCALAR} of {CODE} of array type {TYPE} in an array destructuring assignment",
                self::REMEDIATION_B,
                10047
            ),
            new Issue(
                self::TypeInvalidCallExpressionAssignment,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Probably unused assignment to function result {CODE} for function returning {TYPE}",
                self::REMEDIATION_B,
                10153
            ),
            new Issue(
                self::TypeInvalidExpressionArrayDestructuring,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid value {CODE} of type {TYPE} in an array destructuring assignment, expected {TYPE}",
                self::REMEDIATION_B,
                10077
            ),
            new Issue(
                self::TypeSuspiciousEcho,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Suspicious argument {CODE} of type {TYPE} for an echo/print statement",
                self::REMEDIATION_B,
                10049
            ),

            new Issue(
                self::TypeInvalidThrowsNonObject,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "@throws annotation of {FUNCTIONLIKE} has invalid non-object type {TYPE}, expected a class",
                self::REMEDIATION_B,
                10050
            ),
            new Issue(
                self::TypeInvalidThrowsIsTrait,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "@throws annotation of {FUNCTIONLIKE} has invalid trait type {TYPE}, expected a class",
                self::REMEDIATION_B,
                10051
            ),
            new Issue(
                self::TypeInvalidThrowsIsInterface,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "@throws annotation of {FUNCTIONLIKE} has suspicious interface type {TYPE} for an @throws annotation, expected class (PHP allows interfaces to be caught, so this might be intentional)",
                self::REMEDIATION_B,
                10052
            ),
            new Issue(
                self::TypeInvalidThrowsNonThrowable,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "@throws annotation of {FUNCTIONLIKE} has suspicious class type {TYPE}, which does not extend Error/Exception",
                self::REMEDIATION_B,
                10053
            ),
            new Issue(
                self::TypeInvalidThrowStatementNonThrowable,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "{FUNCTIONLIKE} can throw {CODE} of type {TYPE} here which can't cast to {TYPE}",
                self::REMEDIATION_B,
                10158
            ),
            new Issue(
                self::TypeSuspiciousStringExpression,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Suspicious type {TYPE} of a variable or expression {CODE} used to build a string. (Expected type to be able to cast to a string)",
                self::REMEDIATION_B,
                10066
            ),
            new Issue(
                self::TypeInvalidMethodName,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Instance method name must be a string, got {TYPE}",
                self::REMEDIATION_B,
                10078
            ),
            new Issue(
                self::TypeInvalidStaticMethodName,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Static method name must be a string, got {TYPE}",
                self::REMEDIATION_B,
                10079
            ),
            new Issue(
                self::TypeInvalidCallableMethodName,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Method name of callable must be a string, got {TYPE}",
                self::REMEDIATION_B,
                10080
            ),
            new Issue(
                self::TypeObjectUnsetDeclaredProperty,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,  // There are valid reasons to do this, e.g. for the typed properties V2 RFC or to change serialization
                "Suspicious attempt to unset class {TYPE}'s property \${PROPERTY} declared at {FILE}:{LINE} (This can be done, but is more commonly done for dynamic properties and Phan does not expect this)",
                self::REMEDIATION_B,
                10081
            ),
            new Issue(
                self::TypeModifyImmutableObjectProperty,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Saw attempt to modify {TYPE} {CLASS}'s property \${PROPERTY} declared at {FILE}:{LINE} (immutability of properties is enforced at runtime)",
                self::REMEDIATION_B,
                10181
            ),
            new Issue(
                self::TypeNoAccessiblePropertiesForeach,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Class {TYPE} was passed to foreach, but it does not extend Traversable and none of its declared properties are accessible from this context. (This check excludes dynamic properties)",
                self::REMEDIATION_B,
                10082
            ),
            new Issue(
                self::TypeNoPropertiesForeach,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Class {TYPE} was passed to foreach, but it does not extend Traversable and doesn't have any declared properties. (This check excludes dynamic properties)",
                self::REMEDIATION_B,
                10083
            ),
            new Issue(
                self::TypeSuspiciousNonTraversableForeach,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Class {TYPE} was passed to foreach, but it does not extend Traversable. This may be intentional, because some of that class's declared properties are accessible from this context. (This check excludes dynamic properties)",
                self::REMEDIATION_B,
                10084
            ),
            new Issue(
                self::TypeInvalidRequire,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Require statement was passed an invalid expression of type {TYPE} (expected a string)",
                self::REMEDIATION_B,
                10085
            ),
            new Issue(
                self::TypeInvalidEval,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Eval statement was passed an invalid expression of type {TYPE} (expected a string)",
                self::REMEDIATION_B,
                10086
            ),
            new Issue(
                self::RelativePathUsed,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "{FUNCTION}() statement was passed a relative path {STRING_LITERAL} instead of an absolute path",
                self::REMEDIATION_B,
                10087
            ),
            new Issue(
                self::TypeInvalidCloneNotObject,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Expected an object to be passed to clone() but got {TYPE}",
                self::REMEDIATION_B,
                10088
            ),
            new Issue(
                self::TypePossiblyInvalidCloneNotObject,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Expected an object to be passed to clone() but got possible non-object {TYPE}",
                self::REMEDIATION_B,
                10143
            ),
            new Issue(
                self::TypeInvalidTraitReturn,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Expected a class or interface (or built-in type) to be the real return type of {FUNCTIONLIKE} but got trait {TRAIT}",
                self::REMEDIATION_B,
                10089
            ),
            new Issue(
                self::TypeInvalidTraitParam,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "{FUNCTIONLIKE} is declared to have a parameter \${PARAMETER} with a real type of trait {TYPE} (expected a class or interface or built-in type)",
                self::REMEDIATION_B,
                10090
            ),
            // TODO: Deprecate and remove this issue?
            new Issue(
                self::TypeInvalidBitwiseBinaryOperator,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid non-int/non-string operand provided to operator '{OPERATOR}' between types {TYPE} and {TYPE}",
                self::REMEDIATION_B,
                10091
            ),
            new Issue(
                self::TypeMismatchBitwiseBinaryOperands,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Unexpected mix of int and string operands provided to operator '{OPERATOR}' between types {TYPE} and {TYPE} (expected one type but not both)",
                self::REMEDIATION_B,
                10092
            ),
            new Issue(
                self::InfiniteRecursion,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "{FUNCTIONLIKE} is calling itself in a way that may cause infinite recursion.",
                self::REMEDIATION_B,
                10093
            ),
            new Issue(
                self::PossibleInfiniteRecursionSameParams,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "{FUNCTIONLIKE} is calling itself with the same parameters it was called with. This may cause infinite recursion (Phan does not check for changes to global or shared state).",
                self::REMEDIATION_B,
                10149
            ),
            new Issue(
                self::PossiblyNonClassMethodCall,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Call to method {METHOD} on type {TYPE} that could be a non-object",
                self::REMEDIATION_B,
                10094
            ),
            new Issue(
                self::TypeInvalidCallable,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Saw type {TYPE} which cannot be a callable',
                self::REMEDIATION_B,
                10095
            ),
            new Issue(
                self::TypePossiblyInvalidCallable,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Saw type {TYPE} which is possibly not a callable',
                self::REMEDIATION_B,
                10096
            ),
            new Issue(
                self::TypeComparisonToInvalidClass,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Saw code asserting that an expression has a class, but that class is an invalid/impossible FQSEN {STRING_LITERAL}',
                self::REMEDIATION_B,
                10097
            ),
            new Issue(
                self::TypeComparisonToInvalidClassType,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Saw code asserting that an expression has a class, but saw an invalid/impossible union type {TYPE} (expected {TYPE})',
                self::REMEDIATION_B,
                10098
            ),
            new Issue(
                self::TypeInvalidUnaryOperandIncOrDec,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Invalid operator: unary operand of {STRING_LITERAL} is {TYPE} (expected int or string or float)",
                self::REMEDIATION_B,
                10099
            ),
            new Issue(
                self::TypeInvalidPropertyName,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,  // Not a runtime Error for an instance property
                "Saw a dynamic usage of an instance property with a name of type {TYPE} but expected the name to be a string",
                self::REMEDIATION_B,
                10102
            ),
            new Issue(
                self::TypeInvalidStaticPropertyName,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,  // Likely to be an Error for a static property
                "Saw a dynamic usage of a static property with a name of type {TYPE} but expected the name to be a string",
                self::REMEDIATION_B,
                10103
            ),
            new Issue(
                self::TypeErrorInInternalCall,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Saw a call to an internal function {FUNCTION}() with what would be invalid arguments in strict mode, when trying to infer the return value literal type: {DETAILS}",
                self::REMEDIATION_B,
                10104
            ),
            new Issue(
                self::TypeErrorInOperation,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Saw an error when attempting to infer the type of expression {CODE}: {DETAILS}",
                self::REMEDIATION_B,
                10110
            ),
            new Issue(
                self::TypeMismatchPropertyDefaultReal,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Default value for {TYPE} \${PROPERTY} can't be {CODE} of type {TYPE}",
                self::REMEDIATION_B,
                10108
            ),
            new Issue(
                self::TypeMismatchPropertyDefault,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Default value for {TYPE} \${PROPERTY} can't be {CODE} of type {TYPE} based on phpdoc types",
                self::REMEDIATION_B,
                10159
            ),
            new Issue(
                self::TypeMismatchPropertyReal,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "Assigning {CODE} of type {TYPE}{DETAILS} to property but {PROPERTY} is {TYPE}{DETAILS}",
                self::REMEDIATION_B,
                10137
            ),
            new Issue(
                self::TypeMismatchPropertyProbablyReal,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "Assigning {CODE} of type {TYPE}{DETAILS} to property but {PROPERTY} is {TYPE}{DETAILS} (the inferred real assigned type has nothing in common with the declared phpdoc property type)",
                self::REMEDIATION_B,
                10168
            ),
            new Issue(
                self::TypeMismatchPropertyRealByRef,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                "{CODE} of type {TYPE} may end up assigned to property {PROPERTY} of type {TYPE} by reference at {FILE}:{LINE}",
                self::REMEDIATION_B,
                10150
            ),
            new Issue(
                self::TypeMismatchPropertyByRef,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "{CODE} of type {TYPE} may end up assigned to property {PROPERTY} of type {TYPE} by reference at {FILE}:{LINE}",
                self::REMEDIATION_B,
                10151
            ),
            new Issue(
                self::ImpossibleCondition,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Impossible attempt to cast {CODE} of type {TYPE} to {TYPE}",
                self::REMEDIATION_B,
                10113
            ),
            new Issue(
                self::ImpossibleConditionInLoop,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Impossible attempt to cast {CODE} of type {TYPE} to {TYPE} in a loop body (may be a false positive)",
                self::REMEDIATION_B,
                10118
            ),
            new Issue(
                self::ImpossibleConditionInGlobalScope,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Impossible attempt to cast {CODE} of type {TYPE} to {TYPE} in the global scope (may be a false positive)",
                self::REMEDIATION_B,
                10123
            ),
            new Issue(
                self::RedundantCondition,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Redundant attempt to cast {CODE} of type {TYPE} to {TYPE}",
                self::REMEDIATION_B,
                10114
            ),
            new Issue(
                self::RedundantConditionInLoop,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Redundant attempt to cast {CODE} of type {TYPE} to {TYPE} in a loop body (likely a false positive)",
                self::REMEDIATION_B,
                10119
            ),
            new Issue(
                self::RedundantConditionInGlobalScope,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Redundant attempt to cast {CODE} of type {TYPE} to {TYPE} in the global scope (likely a false positive)",
                self::REMEDIATION_B,
                10124
            ),
            new Issue(
                self::InfiniteLoop,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "The loop condition {CODE} of type {TYPE} is always {TYPE} and nothing seems to exit the loop",
                self::REMEDIATION_B,
                10135
            ),
            new Issue(
                self::PossiblyInfiniteLoop,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                "The loop condition {CODE} does not seem to change within the loop and nothing seems to exit the loop",
                self::REMEDIATION_B,
                10169
            ),
            new Issue(
                self::ImpossibleTypeComparison,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Impossible attempt to check if {CODE} of type {TYPE} is identical to {CODE} of type {TYPE}",
                self::REMEDIATION_B,
                10115
            ),
            new Issue(
                self::ImpossibleTypeComparisonInLoop,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Impossible attempt to check if {CODE} of type {TYPE} is identical to {CODE} of type {TYPE} in a loop body (likely a false positive)",
                self::REMEDIATION_B,
                10120
            ),
            new Issue(
                self::ImpossibleTypeComparisonInGlobalScope,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Impossible attempt to check if {CODE} of type {TYPE} is identical to {CODE} of type {TYPE} in the global scope (likely a false positive)",
                self::REMEDIATION_B,
                10125
            ),
            new Issue(
                self::SuspiciousValueComparison,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Suspicious attempt to compare {CODE} of type {TYPE} to {CODE} of type {TYPE} with operator '{OPERATOR}'",
                self::REMEDIATION_B,
                10131
            ),
            new Issue(
                self::SuspiciousValueComparisonInLoop,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Suspicious attempt to compare {CODE} of type {TYPE} to {CODE} of type {TYPE} with operator '{OPERATOR}' in a loop (likely a false positive)",
                self::REMEDIATION_B,
                10132
            ),
            new Issue(
                self::SuspiciousValueComparisonInGlobalScope,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Suspicious attempt to compare {CODE} of type {TYPE} to {CODE} of type {TYPE} with operator '{OPERATOR}' in the global scope (likely a false positive)",
                self::REMEDIATION_B,
                10133
            ),
            new Issue(
                self::SuspiciousLoopDirection,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Suspicious loop appears to {DETAILS} after each iteration in {CODE}, but the loop condition is {CODE}",
                self::REMEDIATION_B,
                10134
            ),
            new Issue(
                self::SuspiciousWeakTypeComparison,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Suspicious attempt to compare {CODE} of type {TYPE} to {CODE} of type {TYPE}",
                self::REMEDIATION_B,
                10128
            ),
            new Issue(
                self::SuspiciousWeakTypeComparisonInLoop,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Suspicious attempt to compare {CODE} of type {TYPE} to {CODE} of type {TYPE} in a loop body (likely a false positive)",
                self::REMEDIATION_B,
                10129
            ),
            new Issue(
                self::SuspiciousWeakTypeComparisonInGlobalScope,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Suspicious attempt to compare {CODE} of type {TYPE} to {CODE} of type {TYPE} in the global scope (likely a false positive)",
                self::REMEDIATION_B,
                10130
            ),
            new Issue(
                self::SuspiciousTruthyCondition,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Suspicious attempt to check if {CODE} of type {TYPE} is truthy/falsey. This contains both objects/arrays and scalars",
                self::REMEDIATION_B,
                10155
            ),
            new Issue(
                self::SuspiciousTruthyString,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Suspicious attempt to check if {CODE} of type {TYPE} is truthy/falsey. This is false both for '' and '0'",
                self::REMEDIATION_B,
                10156
            ),
            new Issue(
                self::CoalescingNeverNull,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Using non-null {CODE} of type {TYPE} as the left hand side of a null coalescing (??) operation. The right hand side may be unnecessary.",
                self::REMEDIATION_B,
                10116
            ),
            new Issue(
                self::CoalescingNeverNullInLoop,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Using non-null {CODE} of type {TYPE} as the left hand side of a null coalescing (??) operation. The right hand side may be unnecessary. (in a loop body - this is likely a false positive)",
                self::REMEDIATION_B,
                10121
            ),
            new Issue(
                self::CoalescingNeverNullInGlobalScope,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Using non-null {CODE} of type {TYPE} as the left hand side of a null coalescing (??) operation. The right hand side may be unnecessary. (in the global scope - this is likely a false positive)",
                self::REMEDIATION_B,
                10126
            ),
            new Issue(
                self::CoalescingAlwaysNull,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Using {CODE} of type {TYPE} as the left hand side of a null coalescing (??) operation. The left hand side may be unnecessary.",
                self::REMEDIATION_B,
                10117
            ),
            new Issue(
                self::CoalescingAlwaysNullInLoop,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Using {CODE} of type {TYPE} as the left hand side of a null coalescing (??) operation. The left hand side may be unnecessary. (in a loop body - this is likely a false positive)",
                self::REMEDIATION_B,
                10122
            ),
            new Issue(
                self::CoalescingAlwaysNullInGlobalScope,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                "Using {CODE} of type {TYPE} as the left hand side of a null coalescing (??) operation. The left hand side may be unnecessary. (in the global scope - this is likely a false positive)",
                self::REMEDIATION_B,
                10127
            ),
            new Issue(
                self::CoalescingNeverUndefined,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                'Using {CODE} ?? null seems unnecessary - the expression appears to always be defined',
                self::REMEDIATION_B,
                10160
            ),
            new Issue(
                self::DivisionByZero,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Saw {CODE} with a divisor of type {TYPE}',
                self::REMEDIATION_B,
                10145
            ),
            new Issue(
                self::ModuloByZero,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Saw {CODE} with modulus of type {TYPE}',
                self::REMEDIATION_B,
                10146
            ),
            new Issue(
                self::PowerOfZero,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                'Saw {CODE} exponentiating to a power of type {TYPE} (the result will always be 1)',
                self::REMEDIATION_B,
                10147
            ),
            new Issue(
                self::InvalidMixin,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                'Attempting to use a mixin of invalid or missing type {TYPE}',
                self::REMEDIATION_B,
                10152
            ),
            new Issue(
                self::IncompatibleRealPropertyType,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                'Declaration of {PROPERTY} of real type {TYPE} is incompatible with inherited property {PROPERTY} of real type {TYPE} defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                10165
            ),
            new Issue(
                self::AttributeNonClass,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                'Saw attribute with fqsen {TYPE} which was a {CODE} instead of a class',
                self::REMEDIATION_B,
                10170
            ),
            new Issue(
                self::AttributeNonAttribute,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                'Saw attribute {TYPE} which was declared without {CODE}',
                self::REMEDIATION_B,
                10171
            ),
            new Issue(
                self::AttributeNonRepeatable,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                'Saw attribute {CLASS} which was not declared as \Attribute::IS_REPEATABLE in the class definition at {FILE}:{LINE} but had a repeat declaration on line {LINE}',
                self::REMEDIATION_B,
                10172
            ),
            new Issue(
                self::AttributeWrongTarget,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                'Saw use of attribute {CLASS} declared at {FILE}:{LINE} which supports being declared on {DETAILS} but it was declared on {CODE} which requires an attribute declared to support {DETAILS}',
                self::REMEDIATION_B,
                10173
            ),
            new Issue(
                self::TypeUnexpectedEnumCaseType,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                'Saw enum case {CONST} with a value of type {TYPE} that did not match expected type {TYPE}',
                self::REMEDIATION_B,
                10175
            ),
            new Issue(
                self::InstanceMethodWithNoEnumCases,
                self::CATEGORY_TYPE,
                self::SEVERITY_LOW,
                'Saw enum {ENUM} that declares no enum cases but contains instance method {METHOD} declared at {FILE}:{LINE}',
                self::REMEDIATION_B,
                10178
            ),
            // NOTE: This is not considered a syntax error because enums can use traits and traits can also have properties and magic methods.
            new Issue(
                self::EnumCannotHaveProperties,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                'Enum {ENUM} is not allowed to declare instance or static properties but it contains property ${PROPERTY} declared at {FILE}:{LINE}',
                self::REMEDIATION_B,
                10179
            ),
            new Issue(
                self::EnumForbiddenMagicMethod,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                'Enum {ENUM} is not allowed to have the magic method {METHOD} declared at {FILE}:{LINE}',
                self::REMEDIATION_B,
                10180
            ),
            new Issue(
                self::EnumCannotImplement,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                'Classlike {CLASSLIKE} cannot implement {INTERFACE} in php 8.1+',
                self::REMEDIATION_B,
                10187
            ),
            new Issue(
                self::ImpossibleIntersectionType,
                self::CATEGORY_TYPE,
                self::SEVERITY_CRITICAL,
                'Intersection type {TYPE} contains part {TYPE} which cannot cast to the declared type {TYPE}',
                self::REMEDIATION_B,
                10182
            ),
            new Issue(
                self::TypeInvalidArrayKey,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Saw array key {CODE} with key type {TYPE} but expected a value that could cast to int|string',
                self::REMEDIATION_B,
                10183
            ),
            new Issue(
                self::TypeInvalidArrayKeyLiteral,
                self::CATEGORY_TYPE,
                self::SEVERITY_NORMAL,
                'Saw array key {CODE} with key value {SCALAR} but expected a value that could cast to int|string',
                self::REMEDIATION_B,
                10184
            ),

            // Issue::CATEGORY_VARIABLE
            new Issue(
                self::VariableUseClause,
                self::CATEGORY_VARIABLE,
                self::SEVERITY_NORMAL,
                "Non-variables ({CODE}) not allowed within use clause",
                self::REMEDIATION_B,
                12000
            ),

            // Issue::CATEGORY_STATIC
            new Issue(
                self::StaticCallToNonStatic,
                self::CATEGORY_STATIC,
                self::SEVERITY_CRITICAL,
                "Static call to non-static method {METHOD} defined at {FILE}:{LINE}. This is an Error in PHP 8.0+.",
                self::REMEDIATION_B,
                9000
            ),
            new Issue(
                self::StaticPropIsStaticType,
                self::CATEGORY_STATIC,
                self::SEVERITY_LOW,
                "Static property {PROPERTY} is declared to have type {TYPE}, but the only instance is shared among all subclasses (Did you mean {TYPE})",
                self::REMEDIATION_A,
                9001
            ),
            new Issue(
                self::AbstractStaticMethodCall,
                self::CATEGORY_STATIC,
                self::SEVERITY_CRITICAL,
                "Potentially calling an abstract static method {METHOD} in {CODE}",
                self::REMEDIATION_B,
                9002
            ),
            new Issue(
                self::AbstractStaticMethodCallInStatic,
                self::CATEGORY_STATIC,
                self::SEVERITY_CRITICAL,
                "Potentially calling an abstract static method {METHOD} with static:: in {CODE} (the calling static method's class scope may be an abstract class)",
                self::REMEDIATION_B,
                9003
            ),
            new Issue(
                self::AbstractStaticMethodCallInTrait,
                self::CATEGORY_STATIC,
                self::SEVERITY_CRITICAL,
                "Potentially calling an abstract static method {METHOD} on a trait in {CODE}, if the caller's method is called on the trait instead of a concrete class using the trait",
                self::REMEDIATION_B,
                9004
            ),
            new Issue(
                self::StaticClassAccessWithStaticVariable,
                self::CATEGORY_STATIC,
                self::SEVERITY_LOW,
                "Saw access to potentially inherited class element with {CODE} in a function that also uses static variables. The behavior of static variables will change to consistently use one set of static variables per method declaration in php 8.1 and the same method may end up write different values to static variables or do different things after reading static variables in different inherited classes. (This is a simple heuristic, suppress the issue if this is a false positive)",
                self::REMEDIATION_B,
                9005
            ),

            // Issue::CATEGORY_CONTEXT
            new Issue(
                self::ContextNotObject,
                self::CATEGORY_CONTEXT,
                self::SEVERITY_CRITICAL,
                "Cannot access {CLASS} when not in object context",
                self::REMEDIATION_B,
                4000
            ),
            new Issue(
                self::ContextNotObjectInCallable,
                self::CATEGORY_CONTEXT,
                self::SEVERITY_NORMAL,
                "Cannot access {CLASS} when not in object context, but code is using callable {METHOD}",
                self::REMEDIATION_B,
                4001
            ),
            new Issue(
                self::ContextNotObjectUsingSelf,
                self::CATEGORY_CONTEXT,
                self::SEVERITY_NORMAL,
                'Cannot use {CLASS} as type when not in object context in {FUNCTION}',
                self::REMEDIATION_B,
                4002
            ),
            new Issue(
                self::SuspiciousMagicConstant,
                self::CATEGORY_CONTEXT,
                self::SEVERITY_NORMAL,
                'Suspicious reference to magic constant {CODE}: {DETAILS}',
                self::REMEDIATION_B,
                4003
            ),

            // Issue::CATEGORY_DEPRECATED
            new Issue(
                self::DeprecatedFunction,
                self::CATEGORY_DEPRECATED,
                self::SEVERITY_NORMAL,
                "Call to deprecated function {FUNCTIONLIKE} defined at {FILE}:{LINE}{DETAILS}",
                self::REMEDIATION_B,
                5000
            ),
            new Issue(
                self::DeprecatedFunctionInternal,
                self::CATEGORY_DEPRECATED,
                self::SEVERITY_NORMAL,
                "Call to deprecated function {FUNCTIONLIKE}{DETAILS}",
                self::REMEDIATION_B,
                5005
            ),
            new Issue(
                self::DeprecatedClass,
                self::CATEGORY_DEPRECATED,
                self::SEVERITY_NORMAL,
                "Using a deprecated class {CLASS} defined at {FILE}:{LINE}{DETAILS}",
                self::REMEDIATION_B,
                5001
            ),
            new Issue(
                self::DeprecatedProperty,
                self::CATEGORY_DEPRECATED,
                self::SEVERITY_NORMAL,
                "Reference to deprecated property {PROPERTY} defined at {FILE}:{LINE}{DETAILS}",
                self::REMEDIATION_B,
                5002
            ),
            new Issue(
                self::DeprecatedClassConstant,
                self::CATEGORY_DEPRECATED,
                self::SEVERITY_NORMAL,
                "Reference to deprecated class constant {CONST} defined at {FILE}:{LINE}{DETAILS}",
                self::REMEDIATION_B,
                5007
            ),
            new Issue(
                self::DeprecatedInterface,
                self::CATEGORY_DEPRECATED,
                self::SEVERITY_NORMAL,
                "Using a deprecated interface {INTERFACE} defined at {FILE}:{LINE}{DETAILS}",
                self::REMEDIATION_B,
                5003
            ),
            new Issue(
                self::DeprecatedTrait,
                self::CATEGORY_DEPRECATED,
                self::SEVERITY_NORMAL,
                "Using a deprecated trait {TRAIT} defined at {FILE}:{LINE}{DETAILS}",
                self::REMEDIATION_B,
                5004
            ),
            new Issue(
                self::DeprecatedCaseInsensitiveDefine,
                self::CATEGORY_DEPRECATED,
                self::SEVERITY_NORMAL,
                "Creating case-insensitive constants with define() has been deprecated in PHP 7.3",
                self::REMEDIATION_B,
                5006
            ),
            new Issue(
                self::DeprecatedPartiallySupportedCallable,
                self::CATEGORY_DEPRECATED,
                self::SEVERITY_NORMAL,
                'Saw deprecated partially supported callable {METHOD}. This was deprecated in PHP 8.2 and behaves inconsistently and can be called with call_user_func but not $callable() and the referenced class depends on context at call time. In some cases, [{CLASS}::class, {METHOD}] can be used instead.',
                self::REMEDIATION_B,
                5008
            ),
            new Issue(
                self::DeprecatedPartiallySupportedCallableAlternateScope,
                self::CATEGORY_DEPRECATED,
                self::SEVERITY_NORMAL,
                'Saw partially supported callable [{CODE}, {METHOD}]. This was deprecated in PHP 8.2. Invoking methods with alternative scopes can be done with ReflectionMethod::invoke or Closure::bindTo instead.',
                self::REMEDIATION_B,
                5010
            ),
            new Issue(
                self::DeprecatedEncapsVar,
                self::CATEGORY_DEPRECATED,
                self::SEVERITY_NORMAL,
                'Saw deprecated encapsulated string variable syntax for {CODE}: {DETAILS}',
                self::REMEDIATION_B,
                5011
            ),
            new Issue(
                self::DeprecatedImplicitNullableParam,
                self::CATEGORY_DEPRECATED,
                self::SEVERITY_NORMAL,
                'Implicit nullable parameters ({TYPE} {PARAMETER} = null) have been deprecated in PHP 8.4',
                self::REMEDIATION_B,
                5012
            ),

            // Issue::CATEGORY_PARAMETER
            new Issue(
                self::ParamReqAfterOpt,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                'Required parameter {PARAMETER} follows optional {PARAMETER}',
                self::REMEDIATION_B,
                7000
            ),
            new Issue(
                self::ParamTooMany,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Call with {COUNT} arg(s) to {FUNCTIONLIKE} which only takes {COUNT} arg(s) defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                7001
            ),
            new Issue(
                self::ParamTooManyInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                "Call with {COUNT} arg(s) to {FUNCTIONLIKE} which only takes {COUNT} arg(s). This is an ArgumentCountError for internal functions in PHP 8.0+.",
                self::REMEDIATION_B,
                7002
            ),
            new Issue(
                self::ParamTooManyCallable,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Call with {COUNT} arg(s) to {FUNCTIONLIKE} (As a provided callable) which only takes {COUNT} arg(s) defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                7043
            ),
            new Issue(
                self::ParamTooFew,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                "Call with {COUNT} arg(s) to {FUNCTIONLIKE} which requires {COUNT} arg(s) defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                7003
            ),
            new Issue(
                self::ParamTooFewUnpack,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Call with {COUNT} or more arg(s) to {FUNCTIONLIKE} which requires {COUNT} arg(s) defined at {FILE}:{LINE}. This may throw an ArgumentCountError if there are too few args at runtime.",
                self::REMEDIATION_B,
                7063
            ),
            new Issue(
                self::ParamTooFewInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                "Call with {COUNT} arg(s) to {FUNCTIONLIKE} which requires {COUNT} arg(s)",
                self::REMEDIATION_B,
                7004
            ),
            new Issue(
                self::ParamTooFewInternalUnpack,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Call with {COUNT} or more arg(s) to {FUNCTIONLIKE} which requires {COUNT} arg(s). This may throw an ArgumentCountError if there are too few args at runtime.",
                self::REMEDIATION_B,
                7064
            ),
            new Issue(
                self::ParamTooFewCallable,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                "Call with {COUNT} arg(s) to {FUNCTIONLIKE} (as a provided callable) which requires {COUNT} arg(s) defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                7044
            ),
            new Issue(
                self::ParamTooFewInPHPDoc,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                'Call with {COUNT} arg(s) to {FUNCTIONLIKE} which has phpdoc indicating it requires {COUNT} arg(s) (${PARAMETER} is mandatory) defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                7049
            ),
            new Issue(
                self::ParamSpecial1,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} (${PARAMETER}) is {TYPE} but {FUNCTIONLIKE} takes {TYPE} when argument {INDEX} is {TYPE}',
                self::REMEDIATION_B,
                7005
            ),
            new Issue(
                self::ParamSpecial2,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                'Argument {INDEX} (${PARAMETER}) is {TYPE} but {FUNCTIONLIKE} takes {TYPE} when passed only one argument',
                self::REMEDIATION_B,
                7006
            ),
            new Issue(
                self::ParamSpecial3,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "The last argument to {FUNCTIONLIKE} must be of type {TYPE}",
                self::REMEDIATION_B,
                7007
            ),
            new Issue(
                self::ParamSpecial4,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "The second to last argument to {FUNCTIONLIKE} must be of type {TYPE}",
                self::REMEDIATION_B,
                7008
            ),
            new Issue(
                self::ParamTypeMismatch,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Argument {INDEX} is {TYPE} but {FUNCTIONLIKE} takes {TYPE}",
                self::REMEDIATION_B,
                7009
            ),
            new Issue(
                self::ParamSignatureMismatch,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with {METHOD} defined in {FILE}:{LINE}{DETAILS}",
                self::REMEDIATION_B,
                7010
            ),
            new Issue(
                self::ParamSignatureMismatchInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with internal {METHOD}{DETAILS}",
                self::REMEDIATION_B,
                7011
            ),
            new Issue(
                self::ParamRedefined,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                "Redefinition of parameter {PARAMETER}",
                self::REMEDIATION_B,
                7012
            ),
            // TODO: Optionally, change the other message to say that it's based off of phpdoc and LSP in a future PR.
            // NOTE: Incompatibilities in the param list are SEVERITY_NORMAL, because the php interpreter emits a notice.
            // Incompatibilities in the return types are SEVERITY_CRITICAL, because the php interpreter will throw an Error.
            new Issue(
                self::ParamSignatureRealMismatchReturnType,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                "Declaration of {METHOD} should be compatible with {METHOD} (method where the return type in the real signature is '{TYPE}' cannot override method where the return type in the real signature is '{TYPE}') defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7013
            ),
            new Issue(
                self::ParamSignatureRealMismatchReturnTypeInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                "Declaration of {METHOD} should be compatible with internal {METHOD} (method where the return type in the real signature is '{TYPE}' cannot override method where the return type in the real signature is '{TYPE}')",
                self::REMEDIATION_B,
                7014
            ),
            // NOTE: Incompatibilities in param types does not cause the php interpreter to throw an error.
            // It emits a warning instead, so these are SEVERITY_NORMAL.
            new Issue(
                self::ParamSignaturePHPDocMismatchReturnType,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Declaration of real/@method {METHOD} should be compatible with real/@method {METHOD} (method returning '{TYPE}' cannot override method returning '{TYPE}') defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7033
            ),
            new Issue(
                self::ParamSignatureRealMismatchParamType,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with {METHOD} (parameter #{INDEX} of real signature type '{TYPE}' cannot replace original parameter of real signature type '{TYPE}') defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7015
            ),
            new Issue(
                self::ParamSignatureRealMismatchParamTypeInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with internal {METHOD} (parameter #{INDEX} of type '{TYPE}' cannot replace original parameter of type '{TYPE}')",
                self::REMEDIATION_B,
                7016
            ),
            new Issue(
                self::ParamSignaturePHPDocMismatchParamType,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Declaration of real/@method {METHOD} should be compatible with real/@method {METHOD} (parameter #{INDEX} of type '{TYPE}' cannot replace original parameter of type '{TYPE}') defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7034
            ),
            new Issue(
                self::ParamSignatureRealMismatchHasParamType,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                "Declaration of {METHOD} should be compatible with {METHOD} (parameter #{INDEX} has type '{TYPE}' in the real signature which cannot replace original parameter with no type in the real signature) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7017
            ),
            new Issue(
                self::ParamSignatureRealMismatchHasParamTypeInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with internal {METHOD} (parameter #{INDEX} has type '{TYPE}' in the real signature which cannot replace original parameter with no type in the real signature)",
                self::REMEDIATION_B,
                7018
            ),
            new Issue(
                self::ParamSignaturePHPDocMismatchHasParamType,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Declaration of real/@method {METHOD} should be compatible with real/@method {METHOD} (parameter #{INDEX} has type '{TYPE}' which cannot replace original parameter with no type) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7035
            ),
            new Issue(
                self::ParamSignatureRealMismatchHasNoParamType,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,  // NOTE: See allow_method_param_type_widening
                "Declaration of {METHOD} should be compatible with {METHOD} (parameter #{INDEX} with no type in the real signature cannot replace original parameter with type '{TYPE}' in the real signature) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7019
            ),
            new Issue(
                self::ParamSignatureRealMismatchHasNoParamTypeInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with internal {METHOD} (parameter #{INDEX} with no type in the real signature cannot replace original parameter with type '{TYPE}' in the real signature)",
                self::REMEDIATION_B,
                7020
            ),
            new Issue(
                self::ParamSignaturePHPDocMismatchHasNoParamType,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Declaration of real/@method {METHOD} should be compatible with real/@method {METHOD} (parameter #{INDEX} with no type cannot replace original parameter with type '{TYPE}') defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7036
            ),
            new Issue(
                self::ParamSignatureRealMismatchParamVariadic,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with {METHOD} (parameter #{INDEX} is a variadic parameter replacing a non-variadic parameter) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7021
            ),
            new Issue(
                self::ParamSignatureRealMismatchParamVariadicInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with internal {METHOD} (parameter #{INDEX} is a variadic parameter replacing a non-variadic parameter)",
                self::REMEDIATION_B,
                7022
            ),
            new Issue(
                self::ParamSignaturePHPDocMismatchParamVariadic,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Declaration of real/@method {METHOD} should be compatible with real/@method {METHOD} (parameter #{INDEX} is a variadic parameter replacing a non-variadic parameter) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7037
            ),
            new Issue(
                self::ParamSignatureRealMismatchParamNotVariadic,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with {METHOD} (parameter #{INDEX} is a non-variadic parameter replacing a variadic parameter) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7023
            ),
            new Issue(
                self::ParamSignatureRealMismatchParamNotVariadicInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with internal {METHOD} (parameter #{INDEX} is a non-variadic parameter replacing a variadic parameter)",
                self::REMEDIATION_B,
                7024
            ),
            new Issue(
                self::ParamSignaturePHPDocMismatchParamNotVariadic,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Declaration of real/@method {METHOD} should be compatible with real/@method {METHOD} (parameter #{INDEX} is a non-variadic parameter replacing a variadic parameter) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7038
            ),
            new Issue(
                self::ParamSignatureRealMismatchParamIsReference,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with {METHOD} (parameter #{INDEX} is a reference parameter overriding a non-reference parameter) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7025
            ),
            new Issue(
                self::ParamSignatureRealMismatchParamIsReferenceInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with internal {METHOD} (parameter #{INDEX} is a reference parameter overriding a non-reference parameter)",
                self::REMEDIATION_B,
                7026
            ),
            new Issue(
                self::ParamSignaturePHPDocMismatchParamIsReference,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Declaration of real/@method {METHOD} should be compatible with real/@method {METHOD} (parameter #{INDEX} is a reference parameter overriding a non-reference parameter) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7039
            ),
            new Issue(
                self::ParamSignatureRealMismatchParamIsNotReference,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with {METHOD} (parameter #{INDEX} is a non-reference parameter overriding a reference parameter) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7027
            ),
            new Issue(
                self::ParamSignatureRealMismatchParamIsNotReferenceInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with internal {METHOD} (parameter #{INDEX} is a non-reference parameter overriding a reference parameter)",
                self::REMEDIATION_B,
                7028
            ),
            new Issue(
                self::ParamSignaturePHPDocMismatchParamIsNotReference,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Declaration of real/@method {METHOD} should be compatible with real/@method {METHOD} (parameter #{INDEX} is a non-reference parameter overriding a reference parameter) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7040
            ),
            new Issue(
                self::ParamSignatureRealMismatchTooFewParameters,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with {METHOD} (the method override accepts {COUNT} parameter(s), but the overridden method can accept {COUNT}) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7029
            ),
            new Issue(
                self::ParamSignatureRealMismatchTooFewParametersInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with internal {METHOD} (the method override accepts {COUNT} parameter(s), but the overridden method can accept {COUNT})",
                self::REMEDIATION_B,
                7030
            ),
            new Issue(
                self::ParamSignaturePHPDocMismatchTooFewParameters,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Declaration of real/@method {METHOD} should be compatible with real/@method {METHOD} (the method override accepts {COUNT} parameter(s), but the overridden method can accept {COUNT}) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7041
            ),
            new Issue(
                self::ParamSignatureRealMismatchTooManyRequiredParameters,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with {METHOD} (the method override requires {COUNT} parameter(s), but the overridden method requires only {COUNT}) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7031
            ),
            new Issue(
                self::ParamSignatureRealMismatchTooManyRequiredParametersInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} should be compatible with internal {METHOD} (the method override requires {COUNT} parameter(s), but the overridden method requires only {COUNT})",
                self::REMEDIATION_B,
                7032
            ),
            new Issue(
                self::ParamSignaturePHPDocMismatchTooManyRequiredParameters,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Declaration of real/@method {METHOD} should be compatible with real/@method {METHOD} (the method override requires {COUNT} parameter(s), but the overridden method requires only {COUNT}) defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                7042
            ),
            new Issue(
                self::ParamSuspiciousOrder,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                "Argument #{INDEX} of this call to {FUNCTIONLIKE} is typically a literal or constant but isn't, but argument #{INDEX} (which is typically a variable) is a literal or constant. The arguments may be in the wrong order.",
                self::REMEDIATION_B,
                7045
            ),
            new Issue(
                self::ParamTooManyUnpack,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Call with {COUNT} or more args to {FUNCTIONLIKE} which only takes {COUNT} arg(s) defined at {FILE}:{LINE} (argument unpacking was used)",
                self::REMEDIATION_B,
                7046
            ),
            new Issue(
                self::ParamTooManyUnpackInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                "Call with {COUNT} or more args to {FUNCTIONLIKE} which only takes {COUNT} arg(s) (argument unpacking was used)",
                self::REMEDIATION_B,
                7047
            ),
            new Issue(
                self::ParamMustBeUserDefinedClassname,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                "First argument of class_alias() must be a name of user defined class ('{CLASS}' attempted)",
                self::REMEDIATION_B,
                7048
            ),
            new Issue(
                self::ParamNameIndicatingUnused,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                'Saw a parameter named ${PARAMETER}. If this was used to indicate that a parameter is unused to Phan, consider using @unused-param after a param comment or suppressing unused parameter warnings instead. PHP 8.0 introduces support for named parameters, so changing names to suppress unused parameter warnings is no longer recommended.',
                self::REMEDIATION_B,
                7050
            ),
            new Issue(
                self::ParamNameIndicatingUnusedInClosure,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_LOW,
                'Saw a parameter named ${PARAMETER}. If this was used to indicate that a parameter is unused to Phan, consider using @unused-param after a param comment or suppressing unused parameter warnings instead. PHP 8.0 introduces support for named parameters, so changing names to suppress unused parameter warnings is no longer recommended.',
                self::REMEDIATION_B,
                7051
            ),
            new Issue(
                self::UndeclaredNamedArgument,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                'Saw a call with undeclared named argument ({CODE}) to {FUNCTIONLIKE} defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                7052
            ),
            new Issue(
                self::UndeclaredNamedArgumentInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                'Saw a call with undeclared named argument ({CODE}) to {FUNCTIONLIKE}',
                self::REMEDIATION_B,
                7053
            ),
            new Issue(
                self::DuplicateNamedArgument,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                'Saw a call with arguments ({CODE}) and ({CODE}) passed to the same parameter of {FUNCTIONLIKE} defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                7054
            ),
            new Issue(
                self::DuplicateNamedArgumentInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                'Saw a call with arguments ({CODE}) and ({CODE}) passed to the same parameter of {FUNCTIONLIKE}',
                self::REMEDIATION_B,
                7055
            ),
            new Issue(
                self::DefinitelyDuplicateNamedArgument,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                'Cannot repeat the same name for named arguments ({CODE}) and ({CODE})',
                self::REMEDIATION_B,
                7056
            ),
            new Issue(
                self::PositionalArgumentAfterNamedArgument,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                'Saw positional argument ({CODE}) after a named argument {CODE}',
                self::REMEDIATION_B,
                7057
            ),
            new Issue(
                self::ArgumentUnpackingUsedWithNamedArgument,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                'Cannot mix named arguments and argument unpacking in {CODE}',
                self::REMEDIATION_B,
                7058
            ),
            new Issue(
                self::MissingNamedArgument,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                'Missing named argument for {PARAMETER} in call to {METHOD} defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                7059
            ),
            new Issue(
                self::MissingNamedArgumentInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_CRITICAL,
                'Missing named argument for {PARAMETER} in call to {METHOD}',
                self::REMEDIATION_B,
                7060
            ),
            new Issue(
                self::SuspiciousNamedArgumentForVariadic,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                'Passing named argument to a variadic parameter ${PARAMETER} of the same name in a call to {METHOD}. This will set the array offset "{PARAMETER}" of the resulting variadic parameter, not the parameter itself (suppress this if this is deliberate).',
                self::REMEDIATION_B,
                7061
            ),
            new Issue(
                self::SuspiciousNamedArgumentVariadicInternal,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                'Passing named argument {CODE} to the variadic parameter of the internal function {METHOD}. Except for a few internal methods that call methods/constructors dynamically, this is usually not supported by internal functions.',
                self::REMEDIATION_B,
                7062
            ),
            new Issue(
                self::SuspiciousNamedArgumentVariadicInternalUnpack,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                'Saw likely use of named arguments in argument unpacking for {PARAMETER} of type {TYPE} passed to an internal function {FUNCTION}. Except for a few internal methods that call methods/constructors dynamically, this is usually not supported by internal functions.',
                self::REMEDIATION_B,
                7067
            ),
            new Issue(
                self::NoNamedArgument,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                'Saw named argument for {PARAMETER} in call to {METHOD} declared with {COMMENT} defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                7065
            ),
            new Issue(
                self::NoNamedArgumentVariadic,
                self::CATEGORY_PARAMETER,
                self::SEVERITY_NORMAL,
                'Saw likely use of named argument for unpacking {PARAMETER} of type {TYPE} in call to {METHOD} declared with {COMMENT} defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                7066
            ),

            // Issue::CATEGORY_NOOP
            new Issue(
                self::NoopProperty,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused property",
                self::REMEDIATION_B,
                6000
            ),
            new Issue(
                self::NoopArray,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused array",
                self::REMEDIATION_B,
                6001
            ),
            new Issue(
                self::NoopConstant,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused constant",
                self::REMEDIATION_B,
                6002
            ),
            new Issue(
                self::NoopClosure,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused closure",
                self::REMEDIATION_B,
                6003
            ),
            new Issue(
                self::NoopVariable,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused variable",
                self::REMEDIATION_B,
                6004
            ),
            new Issue(
                self::NoopUnaryOperator,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused result of a unary '{OPERATOR}' operator",
                self::REMEDIATION_B,
                6020
            ),
            new Issue(
                self::NoopBinaryOperator,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused result of a binary '{OPERATOR}' operator",
                self::REMEDIATION_B,
                6021
            ),
            new Issue(
                self::NoopStringLiteral,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused result of a string literal {STRING_LITERAL} near this line",
                self::REMEDIATION_B,
                6029
            ),
            new Issue(
                self::NoopEmpty,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused result of an empty({CODE}) check",
                self::REMEDIATION_B,
                6051
            ),
            new Issue(
                self::NoopIsset,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused result of an isset({CODE}) check",
                self::REMEDIATION_B,
                6052
            ),
            new Issue(
                self::NoopCast,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused result of a ({TYPE})({CODE}) cast",
                self::REMEDIATION_B,
                6053
            ),
            new Issue(
                self::NoopEncapsulatedStringLiteral,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused result of an encapsulated string literal",
                self::REMEDIATION_B,
                6030
            ),
            new Issue(
                self::NoopNumericLiteral,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused result of a numeric literal {SCALAR} near this line",
                self::REMEDIATION_B,
                6031
            ),
            // This is used for all classlikes
            new Issue(
                self::UnreferencedClass,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to {TYPE} {CLASS}",
                self::REMEDIATION_B,
                6005
            ),
            new Issue(
                self::UnreferencedConstant,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to global constant {CONST}",
                self::REMEDIATION_B,
                6008
            ),
            new Issue(
                self::UnreferencedFunction,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to function {FUNCTION}",
                self::REMEDIATION_B,
                6009
            ),
            new Issue(
                self::UnreferencedClosure,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to {FUNCTION}",
                self::REMEDIATION_B,
                6010
            ),
            new Issue(
                self::UnreferencedPublicMethod,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to public method {METHOD}",
                self::REMEDIATION_B,
                6011
            ),
            new Issue(
                self::UnreferencedProtectedMethod,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to protected method {METHOD}",
                self::REMEDIATION_B,
                6012
            ),
            new Issue(
                self::UnreferencedPrivateMethod,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to private method {METHOD}",
                self::REMEDIATION_B,
                6013
            ),
            new Issue(
                self::UnreferencedPublicProperty,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to public property {PROPERTY}",
                self::REMEDIATION_B,
                6014
            ),
            new Issue(
                self::UnreferencedPHPDocProperty,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to PHPDoc @property {PROPERTY}",
                self::REMEDIATION_B,
                6056
            ),
            new Issue(
                self::UnreferencedProtectedProperty,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to protected property {PROPERTY}",
                self::REMEDIATION_B,
                6015
            ),
            new Issue(
                self::UnreferencedPrivateProperty,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to private property {PROPERTY}",
                self::REMEDIATION_B,
                6016
            ),
            new Issue(
                self::ReadOnlyPublicProperty,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero write references to public property {PROPERTY}",
                self::REMEDIATION_B,
                6032
            ),
            new Issue(
                self::ReadOnlyProtectedProperty,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero write references to protected property {PROPERTY}",
                self::REMEDIATION_B,
                6033
            ),
            new Issue(
                self::ReadOnlyPrivateProperty,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero write references to private property {PROPERTY}",
                self::REMEDIATION_B,
                6034
            ),
            new Issue(
                self::ReadOnlyPHPDocProperty,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero write references to PHPDoc @property {PROPERTY}",
                self::REMEDIATION_B,
                6058
            ),
            new Issue(
                self::WriteOnlyPublicProperty,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero read references to public property {PROPERTY}",
                self::REMEDIATION_B,
                6025
            ),
            new Issue(
                self::WriteOnlyProtectedProperty,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero read references to protected property {PROPERTY}",
                self::REMEDIATION_B,
                6026
            ),
            new Issue(
                self::WriteOnlyPrivateProperty,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero read references to private property {PROPERTY}",
                self::REMEDIATION_B,
                6027
            ),
            new Issue(
                self::WriteOnlyPHPDocProperty,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero read references to PHPDoc @property {PROPERTY}",
                self::REMEDIATION_B,
                6057
            ),
            new Issue(
                self::UnreferencedPublicClassConstant,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to public class constant {CONST}",
                self::REMEDIATION_B,
                6017
            ),
            new Issue(
                self::UnreferencedProtectedClassConstant,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to protected class constant {CONST}",
                self::REMEDIATION_B,
                6018
            ),
            new Issue(
                self::UnreferencedPrivateClassConstant,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to private class constant {CONST}",
                self::REMEDIATION_B,
                6019
            ),
            new Issue(
                self::UnreferencedUseNormal,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to use statement for classlike/namespace {CLASSLIKE} ({CLASSLIKE})",
                self::REMEDIATION_B,
                6022
            ),
            new Issue(
                self::UnreferencedUseFunction,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to use statement for function {FUNCTION} ({FUNCTION})",
                self::REMEDIATION_B,
                6023
            ),
            new Issue(
                self::UnreferencedUseConstant,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Possibly zero references to use statement for constant {CONST} ({CONST})",
                self::REMEDIATION_B,
                6024
            ),
            new Issue(
                self::UnreachableCatch,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Catch statement for {CLASSLIKE} is unreachable. An earlier catch statement at line {LINE} caught the ancestor class/interface {CLASSLIKE}",
                self::REMEDIATION_B,
                6028
            ),
            new Issue(
                self::UnusedVariable,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Unused definition of variable ${VARIABLE}',
                self::REMEDIATION_B,
                6035
            ),
            new Issue(
                self::UnusedPublicMethodParameter,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Parameter ${PARAMETER} is never used',
                self::REMEDIATION_B,
                6036
            ),
            new Issue(
                self::UnusedPublicFinalMethodParameter,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Parameter ${PARAMETER} is never used',
                self::REMEDIATION_B,
                6037
            ),
            new Issue(
                self::UnusedPublicNoOverrideMethodParameter,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Parameter ${PARAMETER} is never used',
                self::REMEDIATION_B,
                6060
            ),
            new Issue(
                self::UnusedProtectedMethodParameter,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Parameter ${PARAMETER} is never used',
                self::REMEDIATION_B,
                6038
            ),
            new Issue(
                self::UnusedProtectedNoOverrideMethodParameter,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Parameter ${PARAMETER} is never used',
                self::REMEDIATION_B,
                6059
            ),
            new Issue(
                self::UnusedProtectedFinalMethodParameter,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Parameter ${PARAMETER} is never used',
                self::REMEDIATION_B,
                6039
            ),
            new Issue(
                self::UnusedPrivateMethodParameter,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Parameter ${PARAMETER} is never used',
                self::REMEDIATION_B,
                6040
            ),
            new Issue(
                self::UnusedPrivateFinalMethodParameter,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Parameter ${PARAMETER} is never used',
                self::REMEDIATION_B,
                6041
            ),
            new Issue(
                self::UnusedClosureUseVariable,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Closure use variable ${VARIABLE} is never used',
                self::REMEDIATION_B,
                6042
            ),
            new Issue(
                self::ShadowedVariableInArrowFunc,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Short arrow function shadows variable ${VARIABLE} from the outer scope',
                self::REMEDIATION_B,
                6072
            ),
            new Issue(
                self::UnusedClosureParameter,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Parameter ${PARAMETER} is never used',
                self::REMEDIATION_B,
                6043
            ),
            new Issue(
                self::UnusedGlobalFunctionParameter,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Parameter ${PARAMETER} is never used',
                self::REMEDIATION_B,
                6044
            ),
            new Issue(
                self::UnusedVariableValueOfForeachWithKey,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Unused definition of variable ${VARIABLE} as the value of a foreach loop that included keys',
                self::REMEDIATION_B,
                6045
            ),
            new Issue(
                self::EmptyForeach,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Saw a foreach statement with empty iterable type {TYPE}',
                self::REMEDIATION_B,
                6079
            ),
            new Issue(
                self::EmptyForeachBody,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Saw a foreach statement with empty body over array of type {TYPE} (iterating has no side effects)',
                self::REMEDIATION_B,
                6086
            ),
            new Issue(
                self::SideEffectFreeForeachBody,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Saw a foreach loop which probably has no side effects',
                self::REMEDIATION_B,
                6089
            ),
            new Issue(
                self::SideEffectFreeForBody,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Saw a for loop which probably has no side effects',
                self::REMEDIATION_B,
                6090
            ),
            new Issue(
                self::SideEffectFreeWhileBody,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Saw a while loop which probably has no side effects',
                self::REMEDIATION_B,
                6091
            ),
            new Issue(
                self::SideEffectFreeDoWhileBody,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Saw a do-while loop which probably has no side effects',
                self::REMEDIATION_B,
                6092
            ),
            new Issue(
                self::EmptyYieldFrom,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Saw a yield from statement with empty iterable type {TYPE}',
                self::REMEDIATION_B,
                6080
            ),
            new Issue(
                self::UselessBinaryAddRight,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Addition of {TYPE} + {TYPE} {CODE} is probably unnecessary. Array fields from the left hand side will be used instead of each of the fields from the right hand side",
                self::REMEDIATION_B,
                6081
            ),
            new Issue(
                self::SuspiciousBinaryAddLists,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Addition of {TYPE} + {TYPE} {CODE} is a suspicious way to add two lists. Some of the array fields from the left hand side will be part of the result, replacing the fields with the same key from the right hand side (this operator does not concatenate the lists)",
                self::REMEDIATION_B,
                6082
            ),
            new Issue(
                self::UnusedVariableCaughtException,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Unused definition of variable ${VARIABLE} as a caught exception',
                self::REMEDIATION_B,
                6046
            ),
            new Issue(
                self::UnusedVariableReference,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Unused definition of variable ${VARIABLE} as a reference',
                self::REMEDIATION_B,
                6069
            ),
            new Issue(
                self::UnusedVariableStatic,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Unreferenced definition of variable ${VARIABLE} as a static variable',
                self::REMEDIATION_B,
                6070
            ),
            new Issue(
                self::UnusedVariableGlobal,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Unreferenced definition of variable ${VARIABLE} as a global variable',
                self::REMEDIATION_B,
                6071
            ),
            new Issue(
                self::UnusedReturnBranchWithoutSideEffects,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Possibly useless branch in a function where the return value must be used - all branches return values equivalent to {CODE} (previous return is at line {LINE})',
                self::REMEDIATION_B,
                6083
            ),
            new Issue(
                self::RedundantArrayValuesCall,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'Attempting to convert {TYPE} to a list using {FUNCTION} (it is already a list)',
                self::REMEDIATION_B,
                6087
            ),
            new Issue(
                self::UseNormalNamespacedNoEffect,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'The use statement for class/namespace {CLASS} in a namespace has no effect',
                self::REMEDIATION_A,
                6047
            ),
            new Issue(
                self::UseNormalNoEffect,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'The use statement for class/namespace {CLASS} in the global namespace has no effect',
                self::REMEDIATION_A,
                6048
            ),
            new Issue(
                self::UseFunctionNoEffect,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'The use statement for function {FUNCTION} has no effect',
                self::REMEDIATION_A,
                6049
            ),
            new Issue(
                self::UseConstantNoEffect,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                'The use statement for constant {CONST} has no effect',
                self::REMEDIATION_A,
                6050
            ),
            new Issue(
                self::NoopArrayAccess,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused array offset fetch",
                self::REMEDIATION_B,
                6054
            ),
            new Issue(
                self::UnusedGotoLabel,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused goto label {CODE}",
                self::REMEDIATION_B,
                6055
            ),
            new Issue(
                self::VariableDefinitionCouldBeConstant,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Uses of ${VARIABLE} could probably be replaced with a literal or constant',
                self::REMEDIATION_B,
                6061
            ),
            new Issue(
                self::VariableDefinitionCouldBeConstantEmptyArray,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Uses of ${VARIABLE} could probably be replaced with an empty array',
                self::REMEDIATION_B,
                6062
            ),
            new Issue(
                self::VariableDefinitionCouldBeConstantString,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Uses of ${VARIABLE} could probably be replaced with a literal or constant string',
                self::REMEDIATION_B,
                6063
            ),
            new Issue(
                self::VariableDefinitionCouldBeConstantFloat,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Uses of ${VARIABLE} could probably be replaced with a literal or constant float',
                self::REMEDIATION_B,
                6064
            ),
            new Issue(
                self::VariableDefinitionCouldBeConstantInt,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Uses of ${VARIABLE} could probably be replaced with literal integer or a named constant',
                self::REMEDIATION_B,
                6065
            ),
            new Issue(
                self::VariableDefinitionCouldBeConstantTrue,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Uses of ${VARIABLE} could probably be replaced with true or a named constant',
                self::REMEDIATION_B,
                6066
            ),
            new Issue(
                self::VariableDefinitionCouldBeConstantFalse,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Uses of ${VARIABLE} could probably be replaced with false or a named constant',
                self::REMEDIATION_B,
                6067
            ),
            new Issue(
                self::VariableDefinitionCouldBeConstantNull,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Uses of ${VARIABLE} could probably be replaced with null or a named constant',
                self::REMEDIATION_B,
                6068
            ),
            new Issue(
                self::NoopTernary,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused result of a ternary expression where the true/false results don't seem to have side effects",
                self::REMEDIATION_B,
                6073
            ),
            new Issue(
                self::NoopNew,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                "Unused result of new object creation expression in {CODE} (this may be called for the side effects of the non-empty constructor or destructor)",
                self::REMEDIATION_B,
                6084
            ),
            new Issue(
                self::NoopNewNoSideEffects,
                self::CATEGORY_NOOP,
                self::SEVERITY_NORMAL,
                "Unused result of new object creation expression in {CODE} (this is likely free of side effects - there is no known non-empty constructor or destructor)",
                self::REMEDIATION_B,
                6085
            ),
            new Issue(
                self::EmptyPublicMethod,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Empty public method {METHOD}',
                self::REMEDIATION_B,
                6074
            ),
            new Issue(
                self::EmptyProtectedMethod,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Empty protected method {METHOD}',
                self::REMEDIATION_B,
                6075
            ),
            new Issue(
                self::EmptyPrivateMethod,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Empty private method {METHOD}',
                self::REMEDIATION_B,
                6076
            ),
            new Issue(
                self::EmptyFunction,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Empty function {FUNCTION}',
                self::REMEDIATION_B,
                6077
            ),
            new Issue(
                self::EmptyClosure,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Empty closure {FUNCTION}',
                self::REMEDIATION_B,
                6078
            ),
            new Issue(
                self::NoopSwitchCases,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'This switch statement only has the default case',
                self::REMEDIATION_B,
                6088
            ),
            new Issue(
                self::ProvidingUnusedParameter,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Providing an unused optional parameter ${PARAMETER} to {FUNCTIONLIKE} defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                6093
            ),
            new Issue(
                self::ProvidingUnusedParameterOfClosure,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Providing an unused optional parameter ${PARAMETER} to {FUNCTIONLIKE} defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                6094
            ),
            new Issue(
                self::NoopMatchArms,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'This match expression only has the default arm in {CODE}',
                self::REMEDIATION_B,
                6095
            ),
            new Issue(
                self::NoopMatchExpression,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'The result of this match expression is not used and the arms have no side effects (except for possibly throwing UnhandledMatchError) in {CODE}',
                self::REMEDIATION_B,
                6096
            ),
            // TODO: If this is the attributes syntax in php 8.0 stable then this should be become critical.
            new Issue(
                self::NoopRepeatedSilenceOperator,
                self::CATEGORY_NOOP,
                self::SEVERITY_LOW,
                'Saw a repeated silence operator in {CODE}',
                self::REMEDIATION_B,
                6097
            ),

            // Issue::CATEGORY_REDEFINE
            new Issue(
                self::RedefineClass,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_NORMAL,
                "{CLASS} defined at {FILE}:{LINE} was previously defined as {CLASS} at {FILE}:{LINE}",
                self::REMEDIATION_B,
                8000
            ),
            new Issue(
                self::RedefineClassInternal,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_NORMAL,
                "{CLASS} defined at {FILE}:{LINE} was previously defined as {CLASS} internally",
                self::REMEDIATION_B,
                8001
            ),
            // TODO: Split into RedefineMethod, which would be fatal
            new Issue(
                self::RedefineFunction,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_NORMAL,
                "Function {FUNCTION} defined at {FILE}:{LINE} was previously defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                8002
            ),
            new Issue(
                self::RedefineFunctionInternal,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_NORMAL,
                "Function {FUNCTION} defined at {FILE}:{LINE} was previously defined internally",
                self::REMEDIATION_B,
                8003
            ),
            new Issue(
                self::IncompatibleCompositionProp,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_NORMAL,
                "{TRAIT} and {TRAIT} define the same property ({PROPERTY}) in the composition of {CLASS}, as the types {TYPE} and {TYPE} respectively. However, the definition differs and is considered incompatible. Class was composed in {FILE} on line {LINE}",
                self::REMEDIATION_B,
                8004
            ),
            new Issue(
                self::IncompatibleCompositionMethod,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_NORMAL,
                "Declaration of {METHOD} must be compatible with {METHOD} in {FILE} on line {LINE}",
                self::REMEDIATION_B,
                8005
            ),
            // FIXME: It's redundant to include the first FILE:LINE of the declaration in the full issue message
            new Issue(
                self::RedefineClassAlias,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_NORMAL,
                "{CLASS} aliased at {FILE}:{LINE} was previously defined as {CLASS} at {FILE}:{LINE}",
                self::REMEDIATION_B,
                8006
            ),
            new Issue(
                self::RedefinedUsedTrait,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_NORMAL,
                "{CLASS} uses {TRAIT} declared at {FILE}:{LINE} which is also declared at {FILE}:{LINE}. This may lead to confusing errors.",
                self::REMEDIATION_B,
                8007
            ),
            new Issue(
                self::RedefinedInheritedInterface,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_NORMAL,
                "{CLASS} inherits {INTERFACE} declared at {FILE}:{LINE} which is also declared at {FILE}:{LINE}. This may lead to confusing errors.",
                self::REMEDIATION_B,
                8008
            ),
            new Issue(
                self::RedefinedExtendedClass,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_NORMAL,
                "{CLASS} extends {CLASS} declared at {FILE}:{LINE} which is also declared at {FILE}:{LINE}. This may lead to confusing errors. It may be possible to exclude the class that isn't used with exclude_file_list.",
                self::REMEDIATION_B,
                8009
            ),
            new Issue(
                self::RedefinedClassReference,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_NORMAL,
                "Saw reference to {CLASS} declared at {FILE}:{LINE} which is also declared at {FILE}:{LINE}. This may lead to confusing errors. It may be possible to exclude the class that isn't used with exclude_file_list. In addition to normal ways to suppress issues, this issue type can be suppressed on either of the class definitions if it is impractical to exclude one file.",
                self::REMEDIATION_B,
                8012
            ),
            new Issue(
                self::RedefineClassConstant,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_CRITICAL,
                "Class constant {CONST} defined at {FILE}:{LINE} was previously defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                8010
            ),
            new Issue(
                self::RedefineProperty,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_CRITICAL,
                'Property ${PROPERTY} defined at {FILE}:{LINE} was previously defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                8011
            ),
            new Issue(
                self::ReusedEnumCaseValue,
                self::CATEGORY_REDEFINE,
                self::SEVERITY_CRITICAL,
                'Enum case {CONST} has the same value({SCALAR}) as a previous declared enum case {CONST} defined at {FILE}:{LINE}',
                self::REMEDIATION_B,
                8013
            ),

            // Issue::CATEGORY_ACCESS
            new Issue(
                self::AccessPropertyProtected,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Cannot access protected property {PROPERTY} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                1000
            ),
            new Issue(
                self::AccessPropertyPrivate,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Cannot access private property {PROPERTY} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                1001
            ),
            new Issue(
                self::AccessReadOnlyProperty,
                self::CATEGORY_ACCESS,
                self::SEVERITY_LOW,
                "Cannot modify read-only property {PROPERTY} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                1028
            ),
            new Issue(
                self::AccessWriteOnlyProperty,
                self::CATEGORY_ACCESS,
                self::SEVERITY_LOW,
                "Cannot read write-only property {PROPERTY} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                1029
            ),
            new Issue(
                self::AccessReadOnlyMagicProperty,
                self::CATEGORY_ACCESS,
                self::SEVERITY_NORMAL,
                "Cannot modify read-only magic property {PROPERTY} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                1030
            ),
            new Issue(
                self::AccessWriteOnlyMagicProperty,
                self::CATEGORY_ACCESS,
                self::SEVERITY_NORMAL,
                "Cannot read write-only magic property {PROPERTY} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                1031
            ),
            new Issue(
                self::AccessMethodProtected,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Cannot access protected method {METHOD} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                1002
            ),
            new Issue(
                self::AccessMethodPrivate,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Cannot access private method {METHOD} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                1003
            ),
            new Issue(
                self::AccessSignatureMismatch,
                self::CATEGORY_ACCESS,
                self::SEVERITY_NORMAL,
                "Access level to {METHOD} must be compatible with {METHOD} defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                1004
            ),
            new Issue(
                self::AccessSignatureMismatchInternal,
                self::CATEGORY_ACCESS,
                self::SEVERITY_NORMAL,
                "Access level to {METHOD} must be compatible with internal {METHOD}",
                self::REMEDIATION_B,
                1005
            ),
            new Issue(
                self::ConstructAccessSignatureMismatch,
                self::CATEGORY_ACCESS,
                self::SEVERITY_NORMAL,
                "Access level to {METHOD} must be compatible with {METHOD} defined in {FILE}:{LINE} in PHP versions 7.1 and below",
                self::REMEDIATION_B,
                1032
            ),
            new Issue(
                self::PropertyAccessSignatureMismatch,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Access level to {PROPERTY} must be compatible with {PROPERTY} defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                1022
            ),
            new Issue(
                self::PropertyAccessSignatureMismatchInternal,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Access level to {PROPERTY} must be compatible with internal {PROPERTY}",
                self::REMEDIATION_B,
                1023
            ),
            new Issue(
                self::ConstantAccessSignatureMismatch,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Access level to {CONST} must be compatible with {CONST} defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                1024
            ),
            new Issue(
                self::ConstantAccessSignatureMismatchInternal,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Access level to {CONST} must be compatible with internal {CONST}",
                self::REMEDIATION_B,
                1025
            ),
            new Issue(
                self::AccessStaticToNonStaticProperty,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Cannot make static property {PROPERTY} into the non static property {PROPERTY}",
                self::REMEDIATION_B,
                1026
            ),
            new Issue(
                self::AccessNonStaticToStaticProperty,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Cannot make non static property {PROPERTY} into the static property {PROPERTY}",
                self::REMEDIATION_B,
                1027
            ),
            new Issue(
                self::AccessStaticToNonStatic,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Cannot make static method {METHOD}() non static",
                self::REMEDIATION_B,
                1006
            ),
            new Issue(
                self::AccessNonStaticToStatic,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Cannot make non static method {METHOD}() static",
                self::REMEDIATION_B,
                1007
            ),
            new Issue(
                self::AccessClassConstantPrivate,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Cannot access private class constant {CONST} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                1008
            ),
            new Issue(
                self::AccessClassConstantProtected,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Cannot access protected class constant {CONST} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                1009
            ),
            new Issue(
                self::AccessClassConstantOfTraitDirectly,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Cannot directly access class constant {CONST} of trait {TRAIT} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                1036
            ),
            new Issue(
                self::AccessPropertyStaticAsNonStatic,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Accessing static property {PROPERTY} as non static",
                self::REMEDIATION_B,
                1010
            ),
            new Issue(
                self::AccessOwnConstructor,
                self::CATEGORY_ACCESS,
                self::SEVERITY_NORMAL,
                "Accessing own constructor directly via {CLASS}::__construct",
                self::REMEDIATION_B,
                1020
            ),
            new Issue(
                self::AccessMethodProtectedWithCallMagicMethod,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Cannot access protected method {METHOD} defined at {FILE}:{LINE} (if this call should be handled by __call, consider adding a @method tag to the class)",
                self::REMEDIATION_B,
                1011
            ),
            new Issue(
                self::AccessMethodPrivateWithCallMagicMethod,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Cannot access private method {METHOD} defined at {FILE}:{LINE} (if this call should be handled by __call, consider adding a @method tag to the class)",
                self::REMEDIATION_B,
                1012
            ),
            new Issue(
                self::AccessWrongInheritanceCategory,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Attempting to inherit {CLASSLIKE} defined at {FILE}:{LINE} as if it were a {CLASSLIKE}",
                self::REMEDIATION_B,
                1013
            ),
            new Issue(
                self::AccessWrongInheritanceCategoryInternal,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Attempting to inherit internal {CLASSLIKE} as if it were a {CLASSLIKE}",
                self::REMEDIATION_B,
                1014
            ),
            new Issue(
                self::AccessExtendsFinalClass,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Attempting to extend from final class {CLASS} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                1015
            ),
            new Issue(
                self::AccessExtendsFinalClassInternal,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Attempting to extend from final internal class {CLASS}",
                self::REMEDIATION_B,
                1016
            ),
            new Issue(
                self::AccessOverridesFinalMethod,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Declaration of method {METHOD} overrides final method {METHOD} defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                1017
            ),
            new Issue(
                self::AccessOverridesFinalMethodInternal,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Declaration of method {METHOD} overrides final internal method {METHOD}",
                self::REMEDIATION_B,
                1018
            ),
            new Issue(
                self::AccessOverridesFinalMethodPHPDoc,
                self::CATEGORY_ACCESS,
                self::SEVERITY_LOW,
                "Declaration of phpdoc method {METHOD} is an unnecessary override of final method {METHOD} defined in {FILE}:{LINE}",
                self::REMEDIATION_B,
                1019
            ),
            new Issue(
                self::AccessPropertyNonStaticAsStatic,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Accessing non static property {PROPERTY} as static",
                self::REMEDIATION_B,
                1021
            ),
            new Issue(
                self::AccessOverridesFinalMethodInTrait,
                self::CATEGORY_ACCESS,
                self::SEVERITY_NORMAL,
                "Declaration of method {METHOD} overrides final method {METHOD} defined in trait in {FILE}:{LINE}. This is actually allowed in case of traits, even for final methods, but may lead to unexpected behavior",
                self::REMEDIATION_B,
                1033
            ),
            new Issue(
                self::AccessNonPublicAttribute,
                self::CATEGORY_ACCESS,
                self::SEVERITY_NORMAL,
                "Attempting to access attribute {CLASS} with non-public constructor {METHOD} defined at {FILE}:{LINE}. This will throw if ReflectionAttribute->newInstance() is called.",
                self::REMEDIATION_B,
                1034
            ),
            new Issue(
                self::AccessOverridesFinalConstant,
                self::CATEGORY_ACCESS,
                self::SEVERITY_CRITICAL,
                "Declaration of class constant {CONST} overrides final constant {CONST} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                1035
            ),

            // Issue::CATEGORY_COMPATIBLE
            new Issue(
                self::CompatiblePHP7,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "Expression may not be PHP 7 compatible",
                self::REMEDIATION_B,
                3000
            ),
            new Issue(
                self::CompatibleExpressionPHP7,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "{CLASS} expression may not be PHP 7 compatible",
                self::REMEDIATION_B,
                3001
            ),
            new Issue(
                self::CompatibleNullableTypePHP70,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Nullable type '{TYPE}' is not compatible with PHP 7.0",
                self::REMEDIATION_B,
                3002
            ),
            new Issue(
                self::CompatibleShortArrayAssignPHP70,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Square bracket syntax for an array destructuring assignment is not compatible with PHP 7.0",
                self::REMEDIATION_A,
                3003
            ),
            new Issue(
                self::CompatibleKeyedArrayAssignPHP70,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Using array keys in an array destructuring assignment is not compatible with PHP 7.0",
                self::REMEDIATION_B,
                3004
            ),
            new Issue(
                self::CompatibleVoidTypePHP70,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Return type '{TYPE}' means the absence of a return value starting in PHP 7.1. In PHP 7.0, void refers to a class/interface with the name 'void'",
                self::REMEDIATION_B,
                3005
            ),
            new Issue(
                self::CompatibleNeverType,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Return type '{TYPE}' means that a function will not return normally starting in PHP 8.1. In PHP 8.0, 'never' refers to a class/interface with the name 'never'",
                self::REMEDIATION_B,
                3043
            ),
            new Issue(
                self::CompatibleStandaloneType,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Cannot use {TYPE} as a standalone type before php 8.2.",
                self::REMEDIATION_B,
                3050
            ),
            new Issue(
                self::CompatibleTrueType,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Cannot use {TYPE} as a type before php 8.2.",
                self::REMEDIATION_B,
                3051
            ),
            new Issue(
                self::CompatibleIterableTypePHP70,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Return type '{TYPE}' means a Traversable/array value starting in PHP 7.1. In PHP 7.0, iterable refers to a class/interface with the name 'iterable'",
                self::REMEDIATION_B,
                3006
            ),
            new Issue(
                self::CompatibleObjectTypePHP71,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Type '{TYPE}' refers to any object starting in PHP 7.2. In PHP 7.1 and earlier, it refers to a class/interface with the name 'object'",
                self::REMEDIATION_B,
                3007
            ),
            new Issue(
                self::CompatibleMixedType,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Type '{TYPE}' refers to any value starting in PHP 8.0. In PHP 7.4 and earlier, it refers to a class/interface with the name 'mixed'",
                self::REMEDIATION_B,
                3029
            ),
            new Issue(
                self::CompatibleUseVoidPHP70,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Using '{TYPE}' as void will be a syntax error in PHP 7.1 (void becomes the absence of a return type).",
                self::REMEDIATION_B,
                3008
            ),
            new Issue(
                self::CompatibleUseIterablePHP71,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Using '{TYPE}' as iterable will be a syntax error in PHP 7.2 (iterable becomes a native type with subtypes Array and Iterator).",
                self::REMEDIATION_B,
                3009
            ),
            new Issue(
                self::CompatibleUseObjectPHP71,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Using '{TYPE}' as object will be a syntax error in PHP 7.2 (object becomes a native type that accepts any class instance).",
                self::REMEDIATION_B,
                3010
            ),
            new Issue(
                self::CompatibleUseMixed,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Using '{TYPE}' as mixed will be a syntax error in PHP 8.0 (mixed becomes a native type that accepts any value).",
                self::REMEDIATION_B,
                3030
            ),
            new Issue(
                self::CompatibleMultiExceptionCatchPHP70,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Catching multiple exceptions is not supported before PHP 7.1",
                self::REMEDIATION_B,
                3011
            ),
            new Issue(
                self::CompatibleNonCapturingCatch,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Catching exceptions without a variable is not supported before PHP 8.0 in catch ({CLASS})",
                self::REMEDIATION_B,
                3031
            ),
            new Issue(
                self::CompatibleNegativeStringOffset,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "Using negative string offsets is not supported before PHP 7.1 (emits an 'Uninitialized string offset' notice)",
                self::REMEDIATION_B,
                3012
            ),
            new Issue(
                self::CompatibleAutoload,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Declaring an autoloader with function __autoload() was deprecated in PHP 7.2 and is a fatal error in PHP 8.0+. Use spl_autoload_register() instead (supported since PHP 5.1).",
                self::REMEDIATION_B,
                3013
            ),
            new Issue(
                self::CompatibleUnsetCast,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "The unset cast (in {CODE}) was deprecated in PHP 7.2 and is a fatal error in PHP 8.0+.",
                self::REMEDIATION_B,
                3014
            ),
            new Issue(
                self::ThrowStatementInToString,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "{FUNCTIONLIKE} throws {TYPE} here, but throwing in __toString() is a fatal error prior to PHP 7.4",
                self::REMEDIATION_A,
                3015
            ),
            new Issue(
                self::ThrowCommentInToString,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "{FUNCTIONLIKE} documents that it throws {TYPE}, but throwing in __toString() is a fatal error prior to PHP 7.4",
                self::REMEDIATION_A,
                3016
            ),
            new Issue(
                self::CompatibleSyntaxNotice,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "Saw a parse notice: {DETAILS}",
                self::REMEDIATION_B,
                3017
            ),
            // TODO: Update messages to reflect that these were removed in php 8.0
            new Issue(
                self::CompatibleDimAlternativeSyntax,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Array and string offset access syntax with curly braces is deprecated in PHP 7.4. Use square brackets instead. Seen for {CODE}",
                self::REMEDIATION_B,
                3018
            ),
            new Issue(
                self::CompatibleImplodeOrder,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "In php 7.4, passing glue string after the array is deprecated for {FUNCTION}. Should this swap the parameters of type {TYPE} and {TYPE}?",
                self::REMEDIATION_B,
                3019
            ),
            new Issue(
                self::CompatibleUnparenthesizedTernary,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Unparenthesized '{CODE}' is deprecated. Use either '{CODE}' or '{CODE}'",
                self::REMEDIATION_B,
                3020
            ),
            new Issue(
                self::CompatibleTypedProperty,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "Cannot use typed properties before php 7.4. This property group has type {TYPE}",
                self::REMEDIATION_B,
                3021
            ),
            // TODO mention that they will be treated like regular methods.
            new Issue(
                self::CompatiblePHP8PHP4Constructor,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "PHP4 constructors will be removed in php 8, and should not be used. __construct() should be added/used instead to avoid accidentally calling {METHOD}",
                self::REMEDIATION_B,
                3022
            ),
            new Issue(
                self::CompatibleDefaultEqualsNull,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "In PHP 8.0, using a default ({CODE}) that resolves to null will no longer cause the parameter ({PARAMETER}) to be nullable",
                self::REMEDIATION_B,
                3023
            ),
            new Issue(
                self::CompatibleScalarTypePHP56,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "In PHP 5.6, scalar types such as {TYPE} in type signatures are treated like class names",
                self::REMEDIATION_B,
                3024
            ),
            new Issue(
                self::CompatibleAnyReturnTypePHP56,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "In PHP 5.6, return types ({TYPE}) are not supported",
                self::REMEDIATION_B,
                3025
            ),
            new Issue(
                self::CompatibleIntersectionType,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Cannot use intersection types ({TYPE}) before php 8.0",
                self::REMEDIATION_B,
                3045
            ),
            new Issue(
                self::CompatibleUnionType,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Cannot use union types ({TYPE}) before php 8.0",
                self::REMEDIATION_B,
                3026
            ),
            new Issue(
                self::CompatibleStaticType,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "Cannot use static return types before php 8.0",
                self::REMEDIATION_B,
                3027
            ),
            new Issue(
                self::CompatibleThrowExpression,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Cannot use throw as an expression before php 8.0 in {CODE}",
                self::REMEDIATION_B,
                3028
            ),
            new Issue(
                self::CompatibleMatchExpression,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Cannot use match expressions before php 8.0 in {CODE}",
                self::REMEDIATION_B,
                3032
            ),
            new Issue(
                self::CompatibleArrowFunction,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Cannot use arrow functions before php 7.4 in {CODE}",
                self::REMEDIATION_B,
                3033
            ),
            new Issue(
                self::CompatibleNullsafeOperator,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Cannot use nullsafe operator before php 8.0 in {CODE}",
                self::REMEDIATION_B,
                3034
            ),
            new Issue(
                self::CompatibleNamedArgument,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Cannot use named arguments before php 8.0 in argument ({CODE})",
                self::REMEDIATION_B,
                3035
            ),
            new Issue(
                self::CompatibleAssertDeclaration,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Declaring a custom assert() function is a fatal error in PHP 8.0+ because the function has special semantics.",
                self::REMEDIATION_B,
                3041
            ),
            // NOTE: The fact that the native php-ast does not track trailing commas is by design.
            // It exposes the information that php's implementation stores internally,
            // and that information is not available because php itself does not need it.
            new Issue(
                self::CompatibleTrailingCommaParameterList,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "Cannot use trailing commas in parameter or closure use lists before php 8.0 in declaration of {FUNCTIONLIKE}. NOTE: THIS ISSUE CAN ONLY DETECTED BY THE POLYFILL.",
                self::REMEDIATION_B,
                3036
            ),
            new Issue(
                self::CompatibleTrailingCommaArgumentList,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "Cannot use trailing commas in argument lists before php 7.3 in {CODE}. NOTE: THIS ISSUE CAN ONLY DETECTED BY THE POLYFILL.",
                self::REMEDIATION_B,
                3037
            ),
            new Issue(
                self::CompatibleConstructorPropertyPromotion,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "Cannot use constructor property promotion before php 8.0 for {PARAMETER} of {METHOD}",
                self::REMEDIATION_B,
                3038
            ),
            new Issue(
                self::CompatibleAttributeGroupOnSameLine,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Declaring attributes on the same line as a declaration is treated like a line comment before php 8.0 for attribute group {CODE} of {CODE}",
                self::REMEDIATION_B,
                3039
            ),
            new Issue(
                self::CompatibleAttributeGroupOnMultipleLines,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Declaring attributes across multiple lines may be treated like a mix of a line comment and php tokens before php 8.0 for attribute group {CODE} of {CODE} ending around line {LINE}. Note that php-ast does not provide the actual ending line numbers and this issue may be unreliable",
                self::REMEDIATION_B,
                3040
            ),
            new Issue(
                self::CompatibleSerializeInterfaceDeprecated,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "The Serializable interface is deprecated in php 8.1. If you need to retain the Serializable interface for cross-version compatibility, you can suppress this warning for {CLASS} by implementing __serialize() and __unserialize() in addition, which will take precedence over Serializable in PHP versions that support them. If you cannot avoid using Serializable and don't need to support php 8.1 or can tolerate deprecation notices, this issue should be suppressed",
                self::REMEDIATION_B,
                3042
            ),
            new Issue(
                self::CompatibleFinalClassConstant,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Final class constants were not supported prior to php 8.1",
                self::REMEDIATION_B,
                3044
            ),
            new Issue(
                self::CompatibleReadonlyProperty,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_CRITICAL,
                "Cannot use readonly modifier on property {PROPERTY} before php 8.1",
                self::REMEDIATION_B,
                3046
            ),
            new Issue(
                self::CompatibleAccessMethodOnTraitDefinition,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "Calling static method {METHOD} on a trait is deprecated in php 8.1, it should only be called on a class using the trait (in {CODE})",
                self::REMEDIATION_B,
                3047
            ),
            new Issue(
                self::CompatibleAccessPropertyOnTraitDefinition,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                "Accessing static property {PROPERTY} on a trait is deprecated in php 8.1, it should only be accessed on a class using the trait",
                self::REMEDIATION_B,
                3048
            ),
            new Issue(
                self::CompatibleAbstractPrivateMethodInTrait,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                'Trait {TRAIT} declares abstract private function {FUNCTION} which is only allowed in 8.0+',
                self::REMEDIATION_B,
                3049
            ),
            new Issue(
                self::CompatibleTraitConstant,
                self::CATEGORY_COMPATIBLE,
                self::SEVERITY_NORMAL,
                'Trait {TRAIT} declares constant {CONST} which is only allowed in 8.2+',
                self::REMEDIATION_B,
                3052
            ),

            // Issue::CATEGORY_GENERIC
            new Issue(
                self::TemplateTypeConstant,
                self::CATEGORY_GENERIC,
                self::SEVERITY_NORMAL,
                "constant {CONST} may not have a template type",
                self::REMEDIATION_B,
                14000
            ),
            new Issue(
                self::TemplateTypeStaticMethod,
                self::CATEGORY_GENERIC,
                self::SEVERITY_NORMAL,
                "static method {METHOD} does not declare template type in its own comment and may not use the template type of class instances",
                self::REMEDIATION_B,
                14001
            ),
            new Issue(
                self::TemplateTypeStaticProperty,
                self::CATEGORY_GENERIC,
                self::SEVERITY_NORMAL,
                "static property {PROPERTY} may not have a template type",
                self::REMEDIATION_B,
                14002
            ),
            new Issue(
                self::GenericGlobalVariable,
                self::CATEGORY_GENERIC,
                self::SEVERITY_NORMAL,
                "Global variable {VARIABLE} may not be assigned an instance of a generic class",
                self::REMEDIATION_B,
                14003
            ),
            new Issue(
                self::GenericConstructorTypes,
                self::CATEGORY_GENERIC,
                self::SEVERITY_NORMAL,
                "Missing template parameter for type {TYPE} on constructor for generic class {CLASS}",
                self::REMEDIATION_B,
                14004
            ),
            // TODO: Reword this if template types can be used for phan-assert or for compatibility with other arguments
            new Issue(
                self::TemplateTypeNotUsedInFunctionReturn,
                self::CATEGORY_GENERIC,
                self::SEVERITY_NORMAL,
                "Template type {TYPE} not used in return value of function/method {FUNCTIONLIKE}",
                self::REMEDIATION_B,
                14005
            ),
            new Issue(
                self::TemplateTypeNotDeclaredInFunctionParams,
                self::CATEGORY_GENERIC,
                self::SEVERITY_NORMAL,
                "Template type {TYPE} not declared in parameters of function/method {FUNCTIONLIKE} (or Phan can't extract template types for this use case)",
                self::REMEDIATION_B,
                14006
            ),

            // Issue::CATEGORY_INTERNAL
            new Issue(
                self::AccessConstantInternal,
                self::CATEGORY_INTERNAL,
                self::SEVERITY_NORMAL,
                "Cannot access internal constant {CONST} of namespace {NAMESPACE} defined at {FILE}:{LINE} from namespace {NAMESPACE}",
                self::REMEDIATION_B,
                15000
            ),
            new Issue(
                self::AccessClassInternal,
                self::CATEGORY_INTERNAL,
                self::SEVERITY_NORMAL,
                "Cannot access internal {CLASS} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                15001
            ),
            new Issue(
                self::AccessClassConstantInternal,
                self::CATEGORY_INTERNAL,
                self::SEVERITY_NORMAL,
                "Cannot access internal class constant {CONST} defined at {FILE}:{LINE}",
                self::REMEDIATION_B,
                15002
            ),
            new Issue(
                self::AccessPropertyInternal,
                self::CATEGORY_INTERNAL,
                self::SEVERITY_NORMAL,
                "Cannot access internal property {PROPERTY} of namespace {NAMESPACE} defined at {FILE}:{LINE} from namespace {NAMESPACE}",
                self::REMEDIATION_B,
                15003
            ),
            new Issue(
                self::AccessMethodInternal,
                self::CATEGORY_INTERNAL,
                self::SEVERITY_NORMAL,
                "Cannot access internal method {METHOD} of namespace {NAMESPACE} defined at {FILE}:{LINE} from namespace {NAMESPACE}",
                self::REMEDIATION_B,
                15004
            ),

            // Issue::CATEGORY_COMMENT
            new Issue(
                self::InvalidCommentForDeclarationType,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "The phpdoc comment for {COMMENT} cannot occur on a {TYPE}",
                self::REMEDIATION_B,
                16000
            ),
            new Issue(
                self::MisspelledAnnotation,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Saw misspelled annotation {COMMENT}. {SUGGESTION}",
                self::REMEDIATION_B,
                16001
            ),
            new Issue(
                self::UnextractableAnnotation,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Saw unextractable annotation for comment '{COMMENT}'",
                self::REMEDIATION_B,
                16002
            ),
            new Issue(
                self::UnextractableAnnotationPart,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Saw unextractable annotation for a fragment of comment '{COMMENT}': '{COMMENT}'",
                self::REMEDIATION_B,
                16003
            ),
            new Issue(
                self::UnextractableAnnotationSuffix,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Saw a token Phan may have failed to parse after '{COMMENT}': after {TYPE}, saw '{COMMENT}'",
                self::REMEDIATION_B,
                16009
            ),
            new Issue(
                self::UnextractableAnnotationElementName,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Saw possibly unextractable annotation for a fragment of comment '{COMMENT}': after {TYPE}, did not see an element name (will guess based on comment order)",
                self::REMEDIATION_B,
                16010
            ),
            new Issue(
                self::CommentParamWithoutRealParam,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                'Saw an @param annotation for ${PARAMETER}, but it was not found in the param list of {FUNCTIONLIKE}',
                self::REMEDIATION_B,
                16004
            ),
            new Issue(
                self::CommentParamAssertionWithoutRealParam,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                'Saw an @phan-assert annotation for ${PARAMETER}, but it was not found in the param list of {FUNCTIONLIKE}',
                self::REMEDIATION_B,
                16019
            ),
            new Issue(
                self::CommentParamOnEmptyParamList,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                'Saw an @param annotation for ${PARAMETER}, but the param list of {FUNCTIONLIKE} is empty',
                self::REMEDIATION_B,
                16005
            ),
            new Issue(
                self::CommentOverrideOnNonOverrideMethod,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Saw an @override annotation for method {METHOD}, but could not find an overridden method and it is not a magic method",
                self::REMEDIATION_B,
                16006
            ),
            new Issue(
                self::CommentOverrideOnNonOverrideConstant,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Saw an @override annotation for class constant {CONST}, but could not find an overridden constant",
                self::REMEDIATION_B,
                16007
            ),
            new Issue(
                self::CommentOverrideOnNonOverrideProperty,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Saw an @override annotation for property {PROPERTY}, but could not find an overridden property",
                self::REMEDIATION_B,
                16026
            ),
            new Issue(
                self::CommentAbstractOnInheritedConstant,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Class {CLASS} inherits a class constant {CONST} declared at {FILE}:{LINE} marked as {COMMENT} in phpdoc but does not override it",
                self::REMEDIATION_B,
                16023
            ),
            new Issue(
                self::CommentAbstractOnInheritedProperty,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Class {CLASS} inherits a property {PROPERTY} declared at {FILE}:{LINE} marked as {COMMENT} in phpdoc but does not override it",
                self::REMEDIATION_B,
                16024
            ),
            new Issue(
                self::CommentAbstractOnInheritedMethod,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Class {CLASS} inherits a method {METHOD} declared at {FILE}:{LINE} marked as {COMMENT} in phpdoc but does not override it",
                self::REMEDIATION_B,
                16025
            ),
            new Issue(
                self::CommentParamOutOfOrder,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                'Expected @param annotation for ${PARAMETER} to be before the @param annotation for ${PARAMETER}',
                self::REMEDIATION_A,
                16008
            ),
            new Issue(
                self::CommentVarInsteadOfParam,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                'Saw @var annotation for ${VARIABLE} but Phan expects the @param annotation to document the parameter with that name for {FUNCTION}',
                self::REMEDIATION_A,
                16022
            ),
            new Issue(
                self::ThrowTypeAbsent,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "{FUNCTIONLIKE} can throw {CODE} of type {TYPE} here, but has no '@throws' declarations for that class",
                self::REMEDIATION_A,
                16011
            ),
            new Issue(
                self::ThrowTypeAbsentForCall,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "{FUNCTIONLIKE} can throw {TYPE} because it calls {FUNCTIONLIKE}, but has no '@throws' declarations for that class",
                self::REMEDIATION_A,
                16012
            ),
            new Issue(
                self::ThrowTypeMismatch,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "{FUNCTIONLIKE} throws {CODE} of type {TYPE} here, but it only has declarations of '@throws {TYPE}'",
                self::REMEDIATION_A,
                16013
            ),
            new Issue(
                self::ThrowTypeMismatchForCall,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "{FUNCTIONLIKE} throws {TYPE} because it calls {FUNCTIONLIKE}, but it only has declarations of '@throws {TYPE}'",
                self::REMEDIATION_A,
                16014
            ),
            new Issue(
                self::CommentAmbiguousClosure,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Comment {STRING_LITERAL} refers to {TYPE} instead of \\Closure - Assuming \\Closure",
                self::REMEDIATION_A,
                16015
            ),
            new Issue(
                self::CommentDuplicateParam,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                'Comment declares @param ${PARAMETER} multiple times',
                self::REMEDIATION_A,
                16016
            ),
            new Issue(
                self::CommentDuplicateMagicProperty,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                'Comment declares @property* ${PROPERTY} multiple times',
                self::REMEDIATION_A,
                16017
            ),
            // TODO: Support declaring both instance and static methods of the same name
            new Issue(
                self::CommentDuplicateMagicMethod,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                'Comment declares @method {METHOD} multiple times',
                self::REMEDIATION_A,
                16018
            ),
            new Issue(
                self::DebugAnnotation,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                '@phan-debug-var requested for variable ${VARIABLE} - it has union type {TYPE}',
                self::REMEDIATION_A,
                16020
            ),
            new Issue(
                self::CommentObjectInClassConstantType,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Impossible phpdoc declaration that a class constant {CONST} has a type {TYPE} containing objects. This type is ignored during analysis.",
                self::REMEDIATION_B,
                16021
            ),
            new Issue(
                self::CommentUnsupportedUnionType,
                self::CATEGORY_COMMENT,
                self::SEVERITY_LOW,
                "Saw a union type {TYPE} with more than 1 type in a location that does not support union types",
                self::REMEDIATION_B,
                16027
            ),
            new Issue(
                self::CommentUnextractableTypeAlias,
                self::CATEGORY_COMMENT,
                self::SEVERITY_NORMAL,
                "Saw a line {COMMENT} with a type alias that could not be extracted",
                self::REMEDIATION_B,
                16028
            ),
            new Issue(
                self::TypeAliasUsedOutsideComment,
                self::CATEGORY_COMMENT,
                self::SEVERITY_NORMAL,
                "Saw a type alias {TYPE} used outside of a comment - this will refer to a class name instead of {TYPE}",
                self::REMEDIATION_B,
                16029
            ),
            new Issue(
                self::TypeAliasInternalTypeConflict,
                self::CATEGORY_COMMENT,
                self::SEVERITY_NORMAL,
                "Saw attempt to use {TYPE} as a type alias for {TYPE} but internal types cannot be redefined.",
                self::REMEDIATION_B,
                16030
            ),
        ];
        // phpcs:enable Generic.Files.LineLength

        self::sanityCheckErrorList($error_list);
        // Verified the error meets preconditions, now add it.
        $error_map = [];
        foreach ($error_list as $error) {
            $error_type = $error->getType();
            $error_map[$error_type] = $error;
        }

        return $error_map;
    }

    /**
     * @param list<Issue> $issue_list the declared Issue types
     */
    private static function getNextTypeId(array $issue_list, int $invalid_type_id): int
    {
        for ($id = $invalid_type_id + 1; true; $id++) {
            foreach ($issue_list as $error) {
                if ($error->getTypeId() === $id) {
                    continue 2;
                }
            }
            return $id;
        }
    }

    /**
     * @param list<Issue> $error_list
     */
    private static function sanityCheckErrorList(array $error_list): void
    {
        $error_map = [];
        $unique_type_id_set = [];
        foreach ($error_list as $error) {
            $error_type = $error->getType();
            if (\array_key_exists($error_type, $error_map)) {
                throw new AssertionError("Issue of type $error_type has multiple definitions");
            }

            if (\strncmp($error_type, 'Phan', 4) !== 0) {
                throw new AssertionError("Issue of type $error_type should begin with 'Phan'");
            }

            $error_type_id = $error->getTypeId();
            if (\array_key_exists($error_type_id, $unique_type_id_set)) {
                throw new AssertionError("Multiple issues exist with pylint error id $error_type_id - The next available id is " .
                    self::getNextTypeId($error_list, $error_type_id));
            }
            $unique_type_id_set[$error_type_id] = $error;
            $category = $error->getCategory();
            $expected_category_for_type_id_bitpos = (int)\floor($error_type_id / 1000);
            $expected_category_for_type_id = 1 << $expected_category_for_type_id_bitpos;
            if ($category !== $expected_category_for_type_id) {
                throw new AssertionError(\sprintf(
                    "Expected error %s of type %d to be category %d(1<<%d), got 1<<%d\n",
                    $error_type,
                    $error_type_id,
                    $category,
                    (int)\round(\log($category, 2)),
                    $expected_category_for_type_id_bitpos
                ));
            }
            $error_map[$error_type] = $error;
        }
    }

    /**
     * Returns the type name of this issue (e.g. Issue::UndeclaredVariable)
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return int (Unique integer code corresponding to getType())
     */
    public function getTypeId(): int
    {
        return $this->type_id;
    }

    /**
     * Returns the category of this issue (e.g. Issue::CATEGORY_UNDEFINED)
     */
    public function getCategory(): int
    {
        return $this->category;
    }

    /**
     * @return string
     * The name of this issue's category
     */
    public function getCategoryName(): string
    {
        return self::getNameForCategory($this->category);
    }

    /**
     * @return string
     * The name of the category
     */
    public static function getNameForCategory(int $category): string
    {
        return self::CATEGORY_NAME[$category] ?? '';
    }

    /**
     * Returns the severity of this issue (Issue::SEVERITY_LOW, Issue::SEVERITY_NORMAL, or Issue::SEVERITY_CRITICAL)
     */
    public function getSeverity(): int
    {
        return $this->severity;
    }

    /**
     * @return string
     * A descriptive name of the severity of the issue
     */
    public function getSeverityName(): string
    {
        switch ($this->severity) {
            case self::SEVERITY_LOW:
                return 'low';
            case self::SEVERITY_NORMAL:
                return 'normal';
            case self::SEVERITY_CRITICAL:
                return 'critical';
            default:
                throw new \AssertionError('Unknown severity ' . $this->severity);
        }
    }

    /**
     * @suppress PhanUnreferencedPublicMethod (no reporters use this right now)
     */
    public function getRemediationDifficulty(): int
    {
        return $this->remediation_difficulty;
    }

    /**
     * Returns the template text of this issue (e.g. `'Variable ${VARIABLE} is undeclared'`)
     */
    public function getTemplate(): string
    {
        return $this->template;
    }

    /**
     * Returns the number of arguments expected for the format string $this->getTemplate()
     * @suppress PhanAccessReadOnlyProperty lazily computed
     */
    public function getExpectedArgumentCount(): int
    {
        return $this->argument_count ?? $this->argument_count = ConversionSpec::computeExpectedArgumentCount($this->template);
    }

    /**
     * @return string - template with the information needed to colorize this.
     */
    public function getTemplateRaw(): string
    {
        return $this->template_raw;
    }

    /**
     * @param list<mixed> $template_parameters
     * @return IssueInstance
     */
    public function __invoke(
        string $file,
        int $line,
        array $template_parameters = [],
        ?Suggestion $suggestion = null,
        int $column = 0
    ): IssueInstance {
        // TODO: Add callable to expanded union types instead
        return new IssueInstance(
            $this,
            $file,
            $line,
            $template_parameters,
            $suggestion,
            $column
        );
    }

    /**
     * @throws InvalidArgumentException
     * @suppress PhanPluginRemoveDebugCall this is deliberate
     */
    public static function fromType(string $type): Issue
    {
        $error_map = self::issueMap();

        if (!isset($error_map[$type])) {
            // Print a verbose error so that this isn't silently caught.
            \fwrite(\STDERR, "Saw undefined error type $type\n");
            \ob_start();
            \debug_print_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
            \fwrite(\STDERR, \rtrim(\ob_get_clean() ?: "failed to dump backtrace") . \PHP_EOL);
            throw new InvalidArgumentException("Undefined error type $type");
        }

        return $error_map[$type];
    }

    /**
     * @param string $type
     * The type of the issue
     *
     * @param string $file
     * The name of the file where the issue was found
     *
     * @param int $line
     * The line number (start) where the issue was found
     *
     * @param string|int|float|bool|Type|UnionType|FQSEN|TypedElement|UnaddressableTypedElement|Attribute ...$template_parameters
     * Any template parameters required for the issue
     * message
     * @suppress PhanUnreferencedPublicMethod
     */
    public static function emit(
        string $type,
        string $file,
        int $line,
        ...$template_parameters
    ): void {
        self::emitWithParameters(
            $type,
            $file,
            $line,
            $template_parameters
        );
    }

    /**
     * @param string $type
     * The type of the issue
     *
     * @param string $file
     * The name of the file where the issue was found
     *
     * @param int $line
     * The line number (start) where the issue was found
     *
     * @param list<string|int|float|bool|Stringable> $template_parameters
     * Any template parameters required for the issue
     * message
     *
     * @param ?Suggestion $suggestion (optional details on fixing this)
     */
    public static function emitWithParameters(
        string $type,
        string $file,
        int $line,
        array $template_parameters,
        ?Suggestion $suggestion = null,
        int $column = 0
    ): void {
        $issue = self::fromType($type);

        self::emitInstance(
            $issue($file, $line, $template_parameters, $suggestion, $column)
        );
    }

    public const TRACE_BASIC   = 'basic';
    public const TRACE_VERBOSE = 'verbose';

    /**
     * @param IssueInstance $issue_instance
     * An issue instance to emit
     */
    public static function emitInstance(
        IssueInstance $issue_instance
    ): void {
        if (Phan::isExcludedAnalysisFile($issue_instance->getFile())) {
            return;
        }
        if (ConfigPluginSet::instance()->onEmitIssue($issue_instance)) {
            return;
        }
        Phan::getIssueCollector()->collectIssue($issue_instance);
    }

    /**
     * @param CodeBase $code_base
     * The code base within which we're operating
     *
     * @param Context $context
     * The context in which the instance was found
     *
     * @param IssueInstance $issue_instance
     * An issue instance to emit
     */
    public static function maybeEmitInstance(
        CodeBase $code_base,
        Context $context,
        IssueInstance $issue_instance
    ): void {
        // If this issue type has been suppressed in
        // the config, ignore it

        $issue = $issue_instance->getIssue();
        if (self::shouldSuppressIssue(
            $code_base,
            $context,
            $issue->getType(),
            $issue_instance->getLine(),
            $issue_instance->getTemplateParameters(),
            $issue_instance->getSuggestion()
        )) {
            return;
        }

        self::emitInstance($issue_instance);
    }

    /**
     * @param CodeBase $code_base
     * The code base within which we're operating
     *
     * @param Context $context
     * The context in which the node we're going to be looking
     * at exists.
     *
     * @param string $issue_type
     * The type of issue to emit such as Issue::ParentlessClass
     *
     * @param int $lineno
     * The line number where the issue was found
     *
     * @param string|int|float|bool|Stringable ...$parameters
     * Template parameters for the issue's error message.
     * If these are objects, they should define __toString()
     */
    public static function maybeEmit(
        CodeBase $code_base,
        Context $context,
        string $issue_type,
        int $lineno,
        ...$parameters
    ): void {
        self::maybeEmitWithParameters(
            $code_base,
            $context,
            $issue_type,
            $lineno,
            $parameters
        );
    }

    /**
     * @param CodeBase $code_base
     * The code base within which we're operating
     *
     * @param Context $context
     * The context in which the node we're going to be looking
     * at exists.
     *
     * @param string $issue_type
     * The type of issue to emit such as Issue::ParentlessClass
     *
     * @param int $lineno
     * The line number where the issue was found
     *
     * @param list<string|int|float|bool|Stringable> $parameters
     * @param ?Suggestion $suggestion (optional)
     *
     * Template parameters for the issue's error message
     */
    public static function maybeEmitWithParameters(
        CodeBase $code_base,
        Context $context,
        string $issue_type,
        int $lineno,
        array $parameters,
        ?Suggestion $suggestion = null,
        int $column = 0
    ): void {
        if (self::shouldSuppressIssue(
            $code_base,
            $context,
            $issue_type,
            $lineno,
            $parameters,
            $suggestion
        )) {
            return;
        }

        Issue::emitWithParameters(
            $issue_type,
            $context->getFile(),
            $lineno,
            $parameters,
            $suggestion,
            $column
        );
    }

    /**
     * @param list<mixed> $parameters
     */
    public static function shouldSuppressIssue(
        CodeBase $code_base,
        Context $context,
        string $issue_type,
        int $lineno,
        array $parameters,
        ?Suggestion $suggestion = null
    ): bool {
        if (Config::getValue('disable_suppression')) {
            return false;
        }
        // If this issue type has been suppressed in
        // the config, ignore it
        if (\in_array($issue_type, Config::getValue('suppress_issue_types') ?? [], true)) {
            return true;
        }
        // If a white-list of allowed issue types is defined,
        // only emit issues on the white-list
        $whitelist_issue_types = Config::getValue('whitelist_issue_types') ?? [];
        if (\is_array($whitelist_issue_types) && \count($whitelist_issue_types) > 0 &&
            !\in_array($issue_type, $whitelist_issue_types, true)) {
            return true;
        }

        if ($context->hasSuppressIssue($code_base, $issue_type)) {
            return true;
        }

        return ConfigPluginSet::instance()->shouldSuppressIssue(
            $code_base,
            $context,
            $issue_type,
            $lineno,
            $parameters,
            $suggestion
        );
    }
}
