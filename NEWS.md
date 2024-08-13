Phan NEWS

Aug 13 2024, Phan 5.4.5
-----------------------
New features(Analysis):
- Emit `PhanDeprecatedImplicitNullableParam` for uses of [implicitly nullable](https://wiki.php.net/rfc/deprecate-implicitly-nullable-types) parameters (`int $x = null` instead of `?int $x = null`). These can be fixed automatically via the accompanying [IssueFixer](https://github.com/phan/phan/pull/4875/commits/8e22228cae7dcea529eb3c58cbd2b63832bf7595#diff-c4e601780333523f24a0d1d62084b5dabaf37126e7673183c153f36810feb217). [#4875](https://github.com/phan/phan/pull/4875)

New features(CLI, Configs):
- Add `override_return_types` to add types to all return types. Normally, Phan only adds inferred returned types when there is no `@return` type
  or real return type signature. This setting can be disabled on individual methods by adding
  `@phan-hardcode-return-type` to the doc comment.

  Disabled by default. This is more useful with `--analyze-twice` and in conjunction with `PhoundPlugin` to
  detect more callsite possibilities. See the [PR description](https://github.com/phan/phan/pull/4874) where
  this setting was added for more details.

  (Default: `false`)

  Along with this change, we are analyzing the return types of traits a bit more aggressively now. [#4874](https://github.com/phan/phan/pull/4874)

Bug fixes:
- Fix crash in PHP 8.4 due to deprecation warning about implicitly nullable parameters [#4875](https://github.com/phan/phan/pull/4875)

Maintenance:
- Update download links for php-ast zip files [#4876](https://github.com/phan/phan/pull/4876)

Jul 25 2024, Phan 5.4.4
-----------------------
New features:
- New plugin [PhoundPlugin](https://github.com/phan/phan/blob/3a17b9697c63b4807c6cddaa4112962e49cce5d0/src/Phan/Plugin/Internal/PhoundPlugin.php#L13-L31) to populate a sqlite database with callsites of all class elements. The database can be queried to find callsites of a given class element. This was actually introduced in 5.4.3, but not announced at the time. [#18](https://github.com/phan/phan/issues/18)
- In 5.4.4, the accuracy of PhoundPlugin is improved by giving it access to the full method, property, and constant list that phan infers for a given callsite [#4865](https://github.com/phan/phan/pull/4865)

Miscellaneous:
- Fix DOMNode::insertBefore method signature [#4837](https://github.com/phan/phan/pull/4837)
- Update Google\Protobuf\Internal\Message::mergeFromJsonString method signature [#4840](https://github.com/phan/phan/pull/4840)
- Update fputcsv function signature [#4856](https://github.com/phan/phan/pull/4856)
- Update preg_replace_* function signatures [#4856](https://github.com/phan/phan/pull/4856)
- Update ReflectionClass::getConstants and ReflectionObject::getConstants method signatures [#4861](https://github.com/phan/phan/pull/4861)
- Add support to column position on file location in CodeClimatePrinter [#4862](https://github.com/phan/phan/pull/4862)

Dec 26 2023, Phan 5.4.3
-----------------------

New Features(Analysis):
- Automatically inherit `@throws` types from parent methods if `enable_phpdoc_types` is true (which it is by default). (#4757)

Miscellaneous:
- Fix ldap function signatures
- Fix a couple of array spread crash bugs
- Extend supported dependency versions of symfony/console to also allow 7.x (#4822)
- Require php-ast 1.1.1 or newer in PHP 8.3+ if php-ast is installed.

Bug fixes:
- Fix interfaces can extend BackedEnum and UnitEnum (#4782)
- Fix ini_set() signature to take any scalar since PHP 8.1 (#4806)
- Fix RedisCluster setOption/getOption signatures (#4790)
- Don't emit `PhanTypeMismatchUnpackKeyArraySpread` when `minimum_target_php_version` is `'8.1'` or newer. (#4788)
- Fix a couple of array spread crash bugs (#4780)
- Fix crash if match is used inside for-loop (#4767)
- Fix DateTime::getTimestamp return type (#4731)
- Avoid deprecation warning for ASSERT constants in php 8.3+ (#4808)
- Use `ini_set()` instead of `assert_options()` when Phan's assertion options match PHP's assert ini setting defaults to keep those values and avoid deprecation warnings in php 8.3+.
- Fix crash in AST fallback parser when parsing invalid ArgumentExpression in php 8.0+
- Fix false positive warnings and skipping internal constants declared by PECLs prefixed with `\x00` (`APCu`, `immutable_cache`)

Mar 03 2023, Phan 5.4.2
-----------------------

Miscellaneous:
- Fix wording in EmptyStatementListPlugin issue messages.
- Add a few more functions where the return value should be used.
- Fix signature of exif_read_data() #4759
- Make allow_missing_properties setting aware of AllowDynamicProperties attribute for PHP 8.2

Maintenance:
- Require php-ast 1.1.0 or newer in PHP 8.2+ if php-ast is installed.
  This release of php-ast makes the parsing of `AST_ARROW_FUNC` in php 8.2 match older php versions.
- Support parsing of PHP 8.2 syntax such as disjunctive normal form types and `readonly` classes in the polyfill/fallback parser.
- Fix bugs parsing `__halt_compiler()` in the polyfill/fallback parser.

Aug 25 2022, Phan 5.4.1
-----------------------

New Features(Analysis):
- Support parsing php 8.2's disjunctive normal form types (e.g. `A|(B&C)` (https://wiki.php.net/rfc/dnf_types). (#4699)
- Support php 8.2 class constants on trait RFC. (#4687)
  Emit `PhanCompatibleTraitConstant` when using constants on traits with a `minimum_target_php_version` below `'8.2'`
  Emit `PhanAccessClassConstantOfTraitDirectly` when directly referencing the class constant on the trait declaration.
- Emit `PhanTypeModifyImmutableObjectProperty` for PHP 8.1 `readonly` properties when modified anywhere outside of the
  declaring class's scope. (#4710)

Miscellaneous:
- Allow `array_filter` `$callback` to be null (#4715)

Bug fixes:
- Fix false positive warning in PHP < 8.0 for inferring the method signature of `new SoapFault`. (#4724)
  (The constructor was internally declared in reflection as `SoapFault::SoapFault` until php 8.0)
  Adjust the method signature of `SoapFault::__construct` to match the documentation/implementation.

Aug 08 2022, Phan 5.4.0
-----------------------

New Features(CLI, Configs):
- Add `tool/analyze_phpt` to analyze phpt files. See https://www.phpinternalsbook.com/tests/phpt_file_structure.html

New Features(Analysis):
- Support php 8.2's `true` type (https://wiki.php.net/rfc/true-type).
  Emit `PhanCompatTrueType` when `true` is used when `minimum_target_php_version` is less than 8.2.
- Emit `PhanCompatStandaloneType` instead of `PhanInvalidNode` for uses of null/false as real standalone types to support php 8.2 https://wiki.php.net/rfc/null-false-standalone-types
  (Not emitted when `minimum_target_php_version` is 8.2 or higher)
- Improve support for php 8.2 readonly classes and php 8.1 readonly properties

Bug fixes:
- Fix php 8.2.0-dev deprecation notice for `ast\Node` when running Phan in php 5.2.0 with the polyfill instead of the native php-ast version.
- Fix DuplicateArrayKeyPlugin "Implicit conversion from float ... to int" warning causing crash in php 8.1 (#4666)
- Fix slow memory leak of reference cycles in the language server - enable garbage collection for the Phan daemon/language server consistently. (#4665)
  (This fix is only enabled in php 7.3+ when using pcntl, the pcntl fallback already re-enabled garbage collection. php 7.3 improved the garbage collection efficiency for large collections of objects.)
- Move `PhanGenericConstructorTypes` warning to the class inheriting a constructor if needed (#4675)
- Fix crash when combining types for null and an array with PHP_INT_MAX as a key (#4688)
- Fix incorrect type inference for arrays with keys that were invalid UTF-8 (#4688)
- Fix error due to deprecation notice running Phan in php 8.2 due to use of `"${}"` string interpolation (#4692)

Jan 31 2022, Phan 5.3.2
-----------------------

New Features(Analysis):
- Use intersection type of original variable value and array elements when inferring type of `$var` in `in_array($var, $array)`
  instead of just the type of the array elements (#4630)
- Treat type of concatenation of one or more non-real strings as a phpdoc(non-real) string with the real type string. (#4635)
- In `phan --init`, allow inferring php 8.1 as the target php version in the generated config file. (#4655)

Maintenance:
- Allow installing xdebug-handler version ^3.0 (#4639)
- Allow installing symfony/console version ^6.0 (#4642)

Bug fixes:
- Fix AST download link for PHP 8.0+ for Windows (#4645)
- Fix dead code detection for PHP 8.0 non-capturing catch statements. (#4633)
  This should still analyze the catch body even if there is no caught exception variable.
- Ignore phpdoc comment tags that don't start at the start of a line of the doc comment (`* @sometag`) or aren't an inline tag (`* something {@sometag}`). (#4640)
  See https://docs.phpdoc.org/3.0/guide/references/phpdoc/tags/internal.html and https://docs.phpdoc.org/2.9/guides/docblocks.html

  E.g. `* This is not @abstract.` is no longer treated as an abstract method.

Dec 14 2021, Phan 5.3.1
-----------------------

New Features(Analysis):
- Emit `PhanDeprecatedPartiallySupportedCallable` for functions that work with `call_user_func($expr)` but not `$expr()`.
  The literal strings `'self'`, `'parent'`, and `'static'` in `'self::methodName'` or `['self', 'methodName']` in callables were deprecated in PHP 8.2.
- Emit `PhanDeprecatedPartiallySupportedCallableAlternateScope` for uses of callables such as `[new SubClass(), 'Base::method']` specifying an alternate scope.
- Make pass-by-ref vars result in a merged union type, not an overwritten one. (#4602)

Plugins:
- Update format string message for `PhanPluginUnknownClosureParamType` and `PhanPluginUnknownFunctionParamType`.

Bug fixes:
- Avoid crashing when running in PHP 8.2+ after php 8.2 deprecated dynamic(undeclared) properties on classes without `#[AllowDynamicProperties]`.

Nov 13 2021, Phan 5.3.0
-----------------------

New Features(Analysis):
- Fix false positive PhanPossiblyUndeclaredVariable warning when a `try` block unconditionally returns/throws/exits (#4419)
- Fix false positive warnings when analyzing enums, infer that automatically generated methods of enums exist. (#4313)
- Properly resolve template type when `getIterator` returns an `Iterator` that includes a template. (#4556)
- Fix false positives such as `PhanTypeMismatchArgumentNullable` analyzing recursive call with parameter set to literal, without real type information. (#4550)
  (e.g. `function ($retry = true) { if ($retry) {/*...*/} some_call_using_retry($retry); }`)
- Properly detect `PhanUnusedVariable` in try-catch where catch always rethrows. (#4567)
- Make read-only/write-only property detection more accurate for assignment operations (e.g. `+=`, `??=`) and increment/decrement operations. (#4570)
- Improve estimates of array sizes when analyzing calls that unpack values, based on the inferred real type set. (#4577)
- Infer that variadic parameters can have string keys (as of php 8.0) (#4579)
- Emit `PhanParamTooFewUnpack` and `PhanParamTooFewInternalUnpack` to indicate when argument unpacking may provide too few arguments to the called function. (#4577)
- Support the non-standard `@no-named-arguments` PHPDoc comment on function-likes. (#4580, #4152)
  Treat variadic parameters as list types when this annotation is used,
  warn about unpacking string arguments or explicitly passing named arguments to functions using this declaration.
- Warn about argument unpacking that may pass strings to internal functions (e.g. `var_dump(...['a' => 'unsupported'])`) (#4579)
  New issue types: `PhanSuspiciousNamedArgumentVariadicInternalUnpack`
- Support `@phan-type AliasName=UnionType` annotation in inline strings or element comments (#4562)

  These aliases will apply to remaining statements in the current
  **top-level namespace blocks,** similar to use statements, but can also be defined
  in methods and apply to subsequent methods.

  This can be of use in avoiding repetition of phpdoc for long type definitions.

  ```php
  // Alternate inline string version to work around php-ast limitations
  '@phan-type UserData = array{name: string, id: int, createdAt: DateTime}';

  /**
   * @type-alias UserData = array{name: string, id: int, createdAt: DateTime}
   * (applies to phpdoc in this and all subsequent AST nodes in the namespace block)
   */
  class ExampleElementWithPHPDoc {
      /**
       * @param UserData[] $users
       * @return list<UserData>
       */
      public function filterUsers(array $values): array { /* ... */ }
  }

  // The UserData alias is still defined and can be used in other statements

  namespace XYZ;
  // The UserData alias is no longer defined in the new namespace block.
  ```
- When analyzing calls that modify variables as pass by reference, merge old types with existing types
  to account for possibility of branches or early returns (#4602)

Plugins:
- Warn about non-empty try statements that are unlikely to throw in `EmptyStatementListPlugin` (#4555)
- Warn in `AlwaysReturnPlugin` about functions/methods with no return type that have at least one return statement with an expression, but may fall through to the end of the function body without an explicit return (#4587)

Bug fixes:
- Fix off-by-one error when inferring from comparison conditions such as `count($arr) > 0` and `count($arr) >= 1` that the array is non-empty. (#4551)
- Fix checking file path suppressed by baseline (with `/`) on windows (#4149)
- Fix crash when inferring type of array access for scalar other than int/string (e.g. `$arr[true]`) (#4573)
- Properly read `--processes N` CLI flag before checking if phan should restart without `grpc`  (#4608)

Maintenance:
- Account for a few PHP 8.0 signature changes for PDOStatement::fetchAll and Phar methods. (#4569)

Sep 14 2021, Phan 5.2.1
-----------------------

New Features(Analysis):
- Improve analysis of conditions detecting the empty/non-empty array. (#4523)
  E.g. support `if ($x === []) {...} else {...}`, `if (count($x) > 0) {...} else {...}`, etc.
- Raise severity of `PhanTypeNonVarPassByRef` to critical. It throws an Error in php 8.0+. (#3830)
- Infer from conditions such as `in_array($var, $array, true)` that $array is a non-empty array and that $var is of a type found in the elements of $array. (#2511)

Plugins:
- Emit a proper warning when `InvokePHPNativeSyntaxCheckPlugin` is passed a path to a php binary that is missing or invalid (or if the syntax check crashed). (#4116)
  Previously, Phan would crash with an error such as `fwrite(): write of 8196 bytes failed with errno=32 Broken pipe`
- Fix false positive `PhanPluginMoreSpecificActualReturnType` for phpdoc array shape return type and returned generic array. (#4531)

Bug fixes:
- Fix type inference logic that was looking for array specializations rather than array or any array subtype (#4512)
- Fix false positive `PhanUnreferencedClosure`/`PhanUnreferencedFunction` seen when a closure/function name was passed to a function such as `uasort` that already had a plugin analyzing calls of the closure. (#4090, #4519)
- Fix false positive/negative `PhanTypeMissingReturn*` instances. (#4537)

  The check was wrong and should have been checking for a statement list that throws/exits.
  Return statements can be omitted if a function unconditionally exits.

  Also, check for the real `never` return type when emitting issues
- Fix false positive `PhanPossiblyUndefinedGlobalVariable*` instance when `global $var` is used within a conditional. (#4539)
- Fix false positive `PhanPluginRedundantAssignmentInLoop` instance when a variable is modified in a catch statement with a break/continue. (#4542)
- Fix some incorrect line numbers in some plugin issues.
- Fix crash seen when parsing intersection types containing union types such as `non-empty-array&array<'a'|'b'>` (#4544)

Maintenance:
- Fix old return type signature for `get_headers` (#3273)
- Print instructions on how to upgrade php-ast to 1.0.11+ if an outdated version is installed. (#4532)

Aug 26 2021, Phan 5.2.0
-----------------------

Plugins:
- Add `AddNeverReturnTypePlugin`` which will suggest adding a phpdoc return type of `@return never`. (#4468)

Bug fixes:
- When using the polyfill parser, properly parse nullable class property declarations as nullable. (#4492)
- Don't emit PhanIncompatibleRealPropertyType for private base property. (#4426)
- Fix false positive where a method overriding an existing method could be treated as having overrides. (#4502)
- Consistently support `numeric-string` in all phpdoc
- Fix false positive `PhanTypeMismatchPropertyDefaultReal` warning for literal integer and `float` typed property. (#4507)
- Fix false positive warnings such as `PhanImpossibleTypeComparison` about string subtypes not casting to other string subtypes (#4514)

Maintenance:
- Change internal representation of FunctionSignatureMap delta files.
- Add a new exit status bit flag to `BlockExitStatusChecker` to indicate that a function will exit or infinitely loop (`STATUS_NORETURN`) (#4468)
- Internally represent the base function map using php 8.0 signatures instead of php 7.3 - applying deltas backwards has the same result (#4478)

Aug 07 2021, Phan 5.1.0
-----------------------

New Features (Analysis):
- Support running Phan 5 with AST version 80 instead of 85 but warn about php-ast being outdated.

Documentation:
- Update documentation of `--target-php-version` and `--minimum-target-php-version`

Aug 01 2021, Phan 5.0.0
-----------------------

New Features (Analysis):
- Warn about implicitly nullable parameter intersection types (`function(A&B $paramName = null)`) being a compile error.
  New issue type: `PhanTypeMismatchDefaultIntersection`
- Emit `PhanTypeMismatchArgumentSuperType` instead of `PhanTypeMismatchArgument` when passing in an object supertype (e.g. ancestor class) of an object instead of a subtype.
  Emit `PhanTypeMismatchReturnSuperType` instead of `PhanTypeMismatchReturn` when returning an object supertype (e.g. ancestor class) of an object instead of a subtype.

  Phan 5 starts warning about ancestor classes being incompatible argument or return types in cases where it previously allowed it. (#4413)

Jul 24 2021, Phan 5.0.0a4
-------------------------

New Features (Analysis):
- Use the enum class declaration type (int, string, or absent) from AST version 85 to check if enum cases are valid. (#4313)
  New issue types: `PhanSyntaxEnumCaseExpectedValue`, `PhanSyntaxEnumCaseUnexpectedValue`, `PhanTypeUnexpectedEnumCaseType`

Backwards incompatible changes:
- Bump the minimum required AST version from 80 to 85 (Required to analyze php 8.1 enum classes - 'type' was added in AST version 85).
- In php 8.1, require php-ast 1.0.14 to natively parse AST version 85.

Maintenance:
- Upgrade tolerant-php-parser from 0.1.0 to 0.1.1 to prepare to support new php syntax in the polyfill/fallback parser. (#4449)

Bug fixes:
- Fix extraction of reflection attribute target type bitmask from internal attributes such as PHP 8.1's `ReturnTypeWillChange`

Jul 15 2021, Phan 5.0.0a3
-------------------------

New Features (Analysis):
+ Support parsing php 8.1 intersection types in php-ast 1.0.13+ (#4469)
  (not yet supported in polyfill)
+ Support parsing php 8.1 first-class callable syntax in unreleased php-ast version (#4464)
+ Support parsing php 8.1 readonly property modifier (#4463)
+ Support allowing `new` expressions in php 8.1 readonly property modifier (#4460)
+ Emit `PhanTypeInvalidArrayKey` and `PhanTypeInvalidArrayKeyValue` for invalid array key literal types or values.
+ Fix false positive `PhanTypeMissingReturn`/`PhanPluginAlwaysReturnMethod` for method with phpdoc return type of `@return never`
+ Warn about direct access to static methods or properties on traits (instead of classes using those methods/properties) being deprecated in php 8.1 (#4396)
+ Add `Stringable` to allowed types for sprintf variadic arguments. This currently requires explicitly implementing Stringable. (#4466)

Bug fixes:
- Fix a crash when analyzing array literals with invalid key literal values in php 8.1.
- Fix a crash due to deprecation notices for accessing trait methods/properties directly in php 8.1

Jun 26 2021, Phan 5.0.0a2
-------------------------

New Features (Analysis):
- Improve accuracy of checks for weak type overlap for redundant condition warnings on `<=`
- Emit `PhanAccessOverridesFinalConstant` when overriding a final class constant. (#4436)
- Emit `PhanCompatibleFinalClassConstant` if class constants have the final modifier in codebases supporting a minimum target php version older than 8.1 (#4436)
- Analyze class constants declared in interfaces as if they were final in php versions prior to 8.1. (#4436)
- Warn about using $this or superglobals as a parameter or closure use. (#4336)

New Features (CLI)
- Use `var_representation`/polyfill for generating representations of values in issue messages.

Maintenance:
- Upgrade tolerant-php-parser from 0.0.23 to 0.1.0 to prepare to support new php syntax in the polyfill/fallback parser. (#4449)

Bug fixes:
- Properly warn about referencing $this from a `static fn` declared in an instance method. (#4336)
- Fix a crash getting template parameters of intersection types

May 30 2021, Phan 5.0.0a1
-------------------------

Phan 5 introduces support for intersection types, and improves the accuracy of type casting checks and type inference to catch more issues.

This is the unstable branch for alpha releases of Phan 5. Planned/remaining work is described in https://github.com/phan/phan/issues/4413

If you are migrating from Phan 4, it may be useful to set up or update a Phan [baseline file](https://github.com/phan/phan/wiki/Phan-Config-Settings#baseline_path) to catch issues such as nullable type mismatches.
https://github.com/phan/phan/wiki/Tutorial-for-Analyzing-a-Large-Sloppy-Code-Base has other advice on setting up suppressions.
For example, Phan is now more consistently warning about nullable arguments (i.e. both `\X|null` and `?\X`) in a few cases where it may have not warned about passing `\X|null` to a function that expects a non-null type.

If you are using plugins that are not part of Phan itself, they may have issues in Phan 5 due
to additional required methods being added to many of Phan's methods.

New Features (Analysis):
+ Support parsing intersection types in phpdoc and checking if intersection types satisfy type comparisons
+ Support inferring intersection types from conditions such as `instanceof`
+ Warn about impossible type combinations in phpdoc intersection types.
  New issue types: `PhanImpossibleIntersectionType`
+ Improve type checking precision for whether a type can cast to another type.
+ Improve precision of checking if a type is a subtype of another type.
+ Split out warnings about possibly invalid types for property access (non-object) and possibly invalid classes for property access
  New issue types: `PhanPossiblyUndeclaredPropertyOfClass`
+ Also check for partially invalid expressions for instance properties during assignment (`PhanPossiblyUndeclaredProperty*`)
+ Treat `@template-covariant T` as an alias of `@template T` - Previously, that tag was not parsed and `T` would be treated like a (probably undeclared) classlike name. (#4432)

Bug fixes:
+ Fix wrong expression in issue message for PhanPossiblyNullTypeMismatchProperty (#4427)

Breaking Changes:
+ Many internal methods now require a mandatory `CodeBase` instance. This will affect third party plugins.
+ Remove `--language-server-min-diagnostic-delay-ms`.

May 19 2021, Phan 4.0.7 (dev)
-----------------------

Language Server/Daemon mode:
+ Fix an uncaught exception sometimes seen checking for issue suppressions when pcntl is unavailable.

Bug fixes:
+ Don't emit `PhanCompatibleNonCapturingCatch` when `minimum_target_php_version` is `'8.0'` or newer. (#4433)
+ Stop ignoring `@return null` and `@param null $paramName` in phpdoc. (#4453)

  Stop special casing `@param null` now that Phan allows many other literal types in param types.

May 19 2021, Phan 4.0.6
-----------------------

New Features (Analysis):
+ Partially support php 8.1 enums (#4313)
  (infer the real type is the class type, that they cannot be instantiated, that enum values cannot be reused, and that class constants will exist for enum cases)

  New issue types: `PhanReusedEnumCaseValue`, `PhanTypeInstantiateEnum`, `PhanTypeInvalidEnumCaseType`, `PhanSyntaxInconsistentEnum`,
  `PhanInstanceMethodWithNoEnumCases`, `PhanInstanceMethodWithNoEnumCases`, `PhanEnumCannotHaveProperties`, `PhanUnreferencedEnumCase`,
  `PhanEnumForbiddenMagicMethod`.
+ Support php 7.4 covariant return types and contravariant parameter types when the configured or inferred `minimum_target_php_version` is `'7.4'` or newer (#3795)
+ Add initial support for the php 8.1 `never` type (in real return types and phpdoc). (#4380)
  Also add support for the phpdoc aliases `no-return`, `never-return`, and `never-returns`
+ Support casting `iterable<K, V>` to `Traversable<K, V>` with `is_object` or `!is_array` checks
+ Detect more types of expressions that never return when inferring types (e.g. when analyzing `?:`, `??` operators)
+ Use php 8.1's tentative return types from reflection (`hasTentativeReturnType`, `getTentativeReturnType`) to assume real return types of internal functions/methods (#4400)

  This can be disabled by setting `use_tentative_return_type` to `false` (e.g. when using subclasses of internal classes that return incompatible types).
+ Warn about modifying properties of classes that are immutable at runtime (enums, internal classes such as `\Closure` and `\WeakRef`, etc.) (#4313)
  New issue type: `PhanTypeModifyImmutableObjectProperty`

Dead code detection:
+ Infer that functions with a return type of `never` (or phpdoc aliases such as `no-return`) are unreachable when performing control flow analysis.
  This can be disabled by setting `dead_code_detection_treat_never_type_as_unreachable` to false

  Note that control flow is only affected when `UseReturnValuePlugin` is enabled.

Plugins:
+ In `UseReturnValuePlugin`, also start warning about when using the result of an expression that evaluates to `never`
  New issue types: `PhanUseReturnValueOfNever`

Bug fixes:
+ As part of the work on php 7.4 contravariant parameter types,
  don't automatically inherit inferred parameter types from ancestor classlikes when (1) there is no `@param` tag with a type for the parameter on the overriding method and (2) the ancestor parameter types are a subtype of the real parameter types unless

  1. `@inheritDoc` is used.
  2. This is a generic array type such as `array<string,mixed>` that is a specialization of an array type.
     If you want to indicate that the overriding method can be any array type, add `@param array $paramName`.
+ Change composer.json dependency on `composer/xdebug-handler` from `^2.0` to `^1.1|2.0` to avoid conflicting with other libraries or applications that depend on xdebug-handler 1.x (#4382)
+ Support parsing multiple declare directives in the polyfill/fallback parser (#4160)

Apr 29 2021, Phan 4.0.5
-----------------------

New Features (Analysis):
+ Fix handling of some redundant condition checks involving `non-null-mixed` and `null` (#4388, #4391)
+ Emit `PhanCompatibleSerializeInterfaceDeprecated` when a class implements Serializable without also implementing the `__serialize` and `__unserialize` methods as well. (#4387)
  PHP 8.1 deprecates the `Serializable` interface when `__serialize` and `__unserialize` aren't also implemented to be used instead of `serialize`/`unserialize`.

Maintenance:
+ Warn about running phan with multiple processes without pcntl before the analysis phase starts.
+ Start implementing `__serialize`/`__unserialize` in Phan itself in places that use `Serializable`.
+ Use different static variables in different subclasses of `Phan\Language\Type` to account for changes in static variable inheritance in php 8.1. (#4379)

Bug fixes:
+ Allow `?T` to be used in parameter/property types with `@template T` (#4388)

Apr 14 2021, Phan 4.0.4
-----------------------

New Features (CLI, Config):
+ Support `--doc-comment` flag on `tool/make_stubs` to emit the doc comments Phan
  is using for internal elements along with the stubs.
  (these are the doc comments Phan would use for hover text in the language server)
+ Allow `target_php_version` and `minimum_target_php_version` to be 8.1 or newer.

New Features (Analysis):
+ Support the php 8.1 array unpacking with string keys RFC (#4358).
  Don't emit warnings about array unpacking with string keys when `minimum_target_php_version` is '8.1' or newer.
+ Support php 8.1 `array_is_list(array $array): bool` conditional and its negation. (#4348)
+ Fix some false positive issues when trying to eagerly evaluate expressions without emitting issues (#4377)

Bug fixes:
+ Fix crash analyzing union type in trait (#4383)

Maintenance:
+ Update from xdebug-handler 1.x to 2.0.0 to support Xdebug 3 (#4382)

Plugins:
+ Cache plugin instances in `ConfigPluginSet`. This is useful for unit testing stateless plugins which declare the plugin class in the same file returning the plugin instance. (#4352)

Jan 29 2021, Phan 4.0.3
-----------------------

New Features:
+ Support inferring iterable value types/keys from `getIterator` returning an ordinary `Iterator<X>` (previously only inferred types for subclasses of Iterator)

Bug fixes:
+ Fix crash when rendering `[...$x]` in an issue message (#4351)
+ Infer that `if ($x)` `converts non-null-mixed` to `non-empty-mixed`
+ Fix false positive warning case for PhanParamSignaturePHPDocMismatchParamType when a phpdoc parameter has a default value (#4357)
+ Properly warn about accessing a private class constant as `self::CONST_NAME` from inside of a subclass of the constant's declaring class (#4360)
+ Properly infer `allow_method_param_type_widening` from `minimum_target_php_version` to avoid false positive `PhanParamSignatureRealMismatchHasNoParamType`.

Jan 09 2021, Phan 4.0.2
-----------------------

New Features:
+ Improve suggestions for `PhanUndeclaredThis` inside of static methods/closures (#4336)

Language Server/Daemon mode:
+ Properly generate code completions for `::` and `->` at the end of a line on files using Windows line endings(`\r\n`) instead of Unix newlines(`\n`) on any OS (#4345)
  Previously, those were not completed.

Bug fixes:
+ Fix false positive `PhanParamSignatureMismatch` for variadic overriding a function using `func_get_args()` (#4340)
+ Don't emit PhanTypeNoPropertiesForeach for the Countable interface on its own. (#4342)
+ Fix false positive type mismatch warning for casts from callable-object/callable-array/callable-string
  to `function(paramtypes):returntype` (#4343)

Dec 31 2020, Phan 4.0.1
-----------------------

New Features:
+ Emit `PhanCompatibleAssertDeclaration` when declaring a function called `assert`. (#4333)

Bug fixes:
+ Fix false positive `PhanInvalidConstantExpression` for named arguments in attributes (#4334)

Merge changes from Phan 3.2.10

Dec 23 2020, Phan 4.0.0
-----------------------

+ Merge changes from Phan 3.2.9.
+ Relax minimum php-ast restrictions when polyfill is used for Phan 4.
+ Fix conflicting class constant seen in polyfill when php-ast 1.0.6 was installed.

The Phan v4 release line has the following changes from Phan 3:
- Bump the minimum required AST version from 70 to 80 (Required to analyze php 8.0 attributes - the rest of the php 8.0 syntax changes are supported in both Phan 3 and Phan 4).
  A few third party plugins may be affected by the increase of the AST version.
- Supports analyzing whether `#[...]` attributes are used properly when run with PHP 8.0+

Dec 23 2020, Phan 4.0.0-RC2
---------------------------

Merge changes from Phan 3.2.8.

Dec 13 2020, Phan 4.0.0-RC1
---------------------------

Merge changes from Phan 3.2.7.

Nov 27 2020, Phan 4.0.0-alpha5
------------------------------

Merge changes from Phan 3.2.6.

Nov 26 2020, Phan 4.0.0-alpha4
------------------------------

Merge changes from Phan 3.2.5.

Nov 12 2020, Phan 4.0.0-alpha3
------------------------------

Merge changes from Phan 3.2.4.

Oct 12 2020, Phan 4.0.0-alpha2
------------------------------

Merge changes from Phan 3.2.3.

Sep 19 2020, Phan 4.0.0-alpha1
------------------------------

New features (Analysis):
+ Support analyzing PHP 8.0 attributes when Phan is run with php 8.0 or newer.
  Warn if the attribute syntax is likely to be incompatible in php 7.
  Warn if using attributes incorrectly or with incorrect argument lists.

  New issue types: `PhanCompatibleAttributeGroupOnSameLine`, `PhanCompatibleAttributeGroupOnMultipleLines`,
  `PhanAttributeNonAttribute`, `PhanAttributeNonClass`, `PhanAttributeNonRepeatable`,
  `PhanUndeclaredClassAttribute`, `PhanAttributeWrongTarget`, `PhanAccessNonPublicAttribute`.

Backwards incompatible changes:
+ Switch from AST version 70 to AST version 80.
  `php-ast` should be upgraded to version 1.0.10-dev or newer.
+ Drop the no-op `--polyfill-parse-all-doc-comments` flag.

Miscellaneous:
+ Make various classes from Phan implement `Stringable`.

Dec 31 2020, Phan 3.2.10 (dev)
-----------------------

Bug fixes:
+ Fix false positive PhanPossiblyFalseTypeReturn with strict type checking for substr when target php version is 8.0+ (#4335)

Dec 26 2020, Phan 3.2.9
-----------------------

Bug fixes:
+ Fix a few parameter names for issue messages (#4316)
+ Fix bug that could cause Phan not to warn about `SomeClassWithoutConstruct::__construct`
  in some edge cases. (#4323)
+ Properly infer `self` is referring to the current object context even when the object context is unknown in namespaces. (#4070)

Deprecations:
+ Emit a deprecation notice when running this in PHP 7 and php-ast < 1.0.7. (#4189)
  This can be suppressed by setting the environment variable `PHAN_SUPPRESS_AST_DEPRECATION=1`.

Dec 23 2020, Phan 3.2.8
-----------------------

Bug fixes:
+ Fix false positive PhanUnusedVariable for variable redefined in loop (#4301)
+ Fix handling of `-z`/`--signature-compatibility` - that option now enables `analyze_signature_compatibility` instead of disabling it. (#4303)
+ Fix possible `PhanCoalescingNeverUndefined` for variable defined in catch block (#4305)
+ Don't emit `PhanCompatibleConstructorPropertyPromotion` when `minimum_target_php_version` is 8.0 or newer. (#4307)
+ Infer that PHP 8.0 constructor property promotion's properties have write references. (#4308)
  They are written to by the constructor.
+ Inherit phpdoc parameter types for the property declaration in php 8.0 constructor property promotion (#4311)

Dec 13 2020, Phan 3.2.7
-----------------------

New features (Analysis):
+ Update real parameter names to match php 8.0's parameter names for php's own internal methods (including variadics and those with multiple signatures). (#4263)
  Update real parameter names, types, and return types for some PECL extensions.
+ Raise the severity of some php 8.0 incompatibility issues to critical.
+ Fix handling of references after renaming variadic reference parameters of `fscanf`/`scanf`/`mb_convert_variables`
+ Mention if PhanUndeclaredFunction is potentially caused by the target php version being too old. (#4230)
+ Improve real type inference for conditionals on literal types (#4288)
+ Change the way the real type set of array access is inferred for mixes of array shapes and arrays (#4296)
+ Emit `PhanSuspiciousNamedArgumentVariadicInternal` when using named arguments with variadic parameters of internal functions that are
  not among the few reflection functions known to support named arguments. (#4284)
+ Don't suggest instance properties as alternatives to undefined variables inside of static methods.

Bug fixes:
+ Support a `non-null-mixed` type and change the way analysis involving nullability is checked for `mixed` (phpdoc and real). (#4278, #4276)

Nov 27 2020, Phan 3.2.6
-----------------------

New features (Analysis):
+ Update many more real parameter names to match php 8.0's parameter names for php's own internal methods. (#4263)
+ Infer that an instance property exists for PHP 8.0 constructor property promotion. (#3938)
+ Infer types of properties from arguments passed into constructor for PHP 8.0 constructor property promotion. (#3938)
+ Emit `PhanInvalidNode` and `PhanRedefineProperty` when misusing syntax for constructor property promotion. (#3938)
+ Emit `PhanCompatibleConstructorPropertyPromotion` when constructor property promotion is used. (#3938)
+ Emit `PhanSuspiciousMagicConstant` when using `__FUNCTION__` inside of a closure. (#4222)

Nov 26 2020, Phan 3.2.5
-----------------------

New features (Analysis):
+ Convert more internal function signature types from resource to the new object types with `target_php_version` of `8.0`+ (#4245, #4246)
+ Make internal function signature types and counts consistent with PHP 8.0's `.php.stub` files used to generate some reflection information.

Bug fixes
+ Fix logic error inferring the real key type of lists and arrays
  and infer that the real union type of arrays is `array<int,something>`
  when all keys have real type int. (#4251)
+ Fix rendering of processed item count in `--long-progress-bar`.

Miscellaneous:
+ Rename OCI-Collection and OCI-Lob to OCICollection and OCILob internally to prepare for php 8 support.
  (Previously `OCI_Collection` and `OCI_Lob` were used to be valid fqsens internally)

Nov 12 2020, Phan 3.2.4
-----------------------

New features (Analysis):
+ Partially support `self<A>` and `static<B>` in phpdoc types. (#4226)
  This support is incomplete and may run into issues with inheritance.

Bug fixes:
+ Properly infer the literal string value of `__FUNCTION__` for global functions in namespaces (#4231)
+ Fix false positive `PhanPossiblyInfiniteLoop` for `do {} while (false);` that is unchangeably false (#4236)
+ Infer that array_shift and array_pop return null when the passed in array could be empty, not false. (#4239)
+ Handle `PhpToken::getAll()` getting renamed to `PhpToken::tokenize()` in PHP 8.0.0RC4. (#4189)

Oct 12 2020, Phan 3.2.3
-----------------------

New features (CLI, Config):
+ Add `light_high_contrast` support for `--color-scheme`. (#4203)
  This may be useful in terminals or CI web pages that use white backgrounds.

New features (Analysis):
+ Infer that `parent::someMethodReturningStaticType()` is a subtype of the current class, not just the parent class. (#4202)
+ Support phpdoc `@abstract` or `@phan-abstract` on non-abstract class constants, properties, and methods
  to indicate that the intent is for non-abstract subclasses to override the definition. (#2278, #2285)
  New issue types: `PhanCommentAbstractOnInheritedConstant`, `PhanCommentAbstractOnInheritedProperty`, `PhanCommentOverrideOnNonOverrideProperty`

  For example, code using `static::SOME_CONST` or `static::$SOME_PROPERTY` or `$this->someMethod()`
  may declare a placeholder `@abstract` constant/property/method,
  and use this annotation to ensure that all non-abstract subclasses override the constant/property/method
  (if using real abstract methods is not practical for a use case)
+ Warn about `@override` on properties that do not override an ancestor's property definition.
  New issue type: `PhanCommentOverrideOnNonOverrideProperty`.
  (Phan already warns for constants and methods)

Plugins:
+ Emit `PhanPluginUseReturnValueGenerator` for calling a function returning a generator without using the returned Generator. (#4013)

Bug fixes:
+ Properly analyze the right hand side for `$cond || throw ...;` (e.g. emit `PhanCompatibleThrowException`) (#4199)
+ Don't infer implications of `left || right` on the right hand expression when the right hand side has no side effects. (#4199)
+ Emit `PhanTypeInvalidThrowStatementNonThrowable` for thrown expressions that definitely aren't `\Throwable`
  even when `warn_about_undocumented_throw_statements` is disabled or the throw expression is in the top level scope. (#4200)
+ Increase the minimum requirements in composer.json to what Phan actually requires. (#4217)

Sep 19 2020, Phan 3.2.2
-----------------------

New features (Analysis):
+ Improve handling of missing argument info when analyzing calls to functions/methods.
  This will result in better issue detection for inherited methods or methods which Phan does not have type info for.

Bug fixes:
+ Fix false positive `PhanUnusedVariable` in `for (; $loop; ...) {}` (#4191)
+ Don't infer defaults of ancestor class properties when analyzing the implementation of `__construct`. (#4195)
  This is only affects projects where the config setting `infer_default_properties_in_construct` is overridden to be enabled.
+ Check `minimum_target_php_version` for more compatibility warnings about parameter types.

Sep 13 2020, Phan 3.2.1
-----------------------

New features (Analysis):
+ Don't compare parameter types against alternate method signatures which have too many required parameters.
  (e.g. warn about `max([])` but not `max([], [1])`)
+ Support `/** @unused-param $param_name */` in doc comments as an additional way to support suppressing warnings about individual parameters being unused.
+ Warn about loop conditions that potentially don't change due to the body of the loop.
  This check uses heuristics and is prone to false positives.
  New issue types: `PhanPossiblyInfiniteLoop`
+ Treat `unset($x);` as shadowing variable definitions during dead code detection.
+ Change the way `$i++`, `--$i`, etc. are analyzed during dead code detection
+ Properly enable `allow_method_param_type_widening` by default when the inferred `minimum_target_php_version` is `'7.2'` or newer. (#4168)
+ Start preparing for switching to AST version 80 in an upcoming Phan 4 release. (#4167)`

Bug fixes:
+ Fix various crashes in edge cases.
+ Fix crash with adjacent named labels for gotos.
+ Fix false positive unused parameter warning with php 8.0 constructor property promotion.

Plugins:
+ Warn about `#` comments in `PHPDocInWrongCommentPlugin` if they're not used for the expected `#[` syntax of php 8.0 attributes.

Maintenance:
+ Update polyfill/fallback parser to properly skip attributes in php 8.0.
  The upcoming Phan 4 release will support analyzing attributes, which requires AST version 80.

Aug 25 2020, Phan 3.2.0
-----------------------

New features (CLI, Config):
+ **Add the `minimum_target_php_version` config setting and `--minimum-target-php-version` CLI flag.** (#3939)
  Phan will use this instead of `target_php_version` for some backwards compatibility checks
  (i.e. to check that the feature in question is supported by the oldest php version the project supports).

  If this is not configured, Phan will attempt to use the composer.json version ranges if they are available.
  Otherwise, `target_php_version` will be used.

  Phan will use `target_php_version` instead if `minimum_target_php_version` is greater than `target_php_version`.

  Update various checks to use `minimum_target_php_version` instead of `target_php_version`.
+ Add `--always-exit-successfully-after-analysis` flag.
  By default, phan exits with a non-zero exit code if 1 or more unsuppressed issues were reported.
  When this CLI flag is set, phan will instead exit with exit code 0 as long as the analysis completed.
+ Include the installed php-ast version and the php version used to run Phan in the output of `phan --version`. (#4147)

New features (Analysis):
+ Emit `PhanCompatibleArrowFunction` if using arrow functions with a minimum target php version older than php 7.4.
+ Emit `PhanCompatibleMatchExpression` if using match expressions with a minimum target php version older than php 8.0.
+ Emit `PhanNoopRepeatedSilenceOperator` for `@@expr` or `@(@expr)`.
  This is less efficient and only makes a difference in extremely rare edge cases.
+ Avoid false positives for bitwise operations on floats such as unsigned 64-bit numbers (#4106)
+ Incomplete support for analyzing calls with php 8.0's named arguments. (#4037)
  New issue types: `PhanUndeclaredNamedArgument*`, `PhanDuplicateNamedArgument*`,
  `PhanMissingNamedArgument*`,
  `PhanDefinitelyDuplicateNamedArgument`, `PhanPositionalArgumentAfterNamedArgument`, and
  `PhanArgumentUnpackingUsedWithNamedArgument`, `PhanSuspiciousNamedArgumentForVariadic`
+ Incomplete support for analyzing uses of PHP 8.0's nullsafe operator(`?->`) for property reads and method calls. (#4067)
+ Warn about using `@var` where `@param` should be used (#1366)
+ Treat undefined variables as definitely null/undefined in various places
  when they are used outside of loops and the global scope. (#4148)
+ Don't warn about undeclared global constants after `defined()` conditions. (#3337)
  Phan will infer a broad range of types for these constants that can't be narrowed.
+ Parse `lowercase-string` and `non-empty-lowercase-string` in phpdoc for compatibility, but treat them like ordinary strings.
+ Emit `PhanCompatibleTrailingCommaParameterList` and `PhanCompatibleTrailingCommaArgumentList` **when the polyfill is used**. (#2269)
  Trailing commas in argument lists require a minimum target version of php 7.3+,
  and trailing commas in parameters or closure use lists require php 8.0+.

  This is only available in the polyfill because the native `php-ast` parser
  exposes the information that php itself tracks internally,
  and php deliberately does not track whether any of these node types have trailing commas.

  There are already other ways to detect these backwards compatibility issues,
  such as `--native-syntax-check path/to/php7.x`.
+ Warn about variable definitions that are unused due to fallthroughs in switch statements. (#4162)

Plugins:
+ Add more aliases to `DeprecateAliasPlugin`

Miscellaneous:
+ Raise the severity of `PhanUndeclaredConstant` and `PhanStaticCallToNonStatic` from normal to critical.
  Undeclared constants will become a thrown `Error` at runtime in PHP 8.0+.

Bug fixes:
+ Suppress `PhanParamNameIndicatingUnused` in files loaded from `autoload_internal_extension_signatures`
+ Improve compatibility of polyfill/fallback parser with php 8.0
+ Also try to check against the realpath() of the current working directory when converting absolute paths
  to relative paths.
+ Generate baseline files with `/` instead of `\` on Windows in `--save-baseline` (#4149)

Jul 31 2020, Phan 3.1.1
-----------------------

New features (CLI, Config):
+ Add `--baseline-summary-type={ordered_by_count,ordered_by_type,none}` to control the generation
  of the summary comment generated by `--save-baseline=path/to/baseline.php` (#4044)
  (overrides the new `baseline_summary_type` config).
  The default comment summary (`ordered_by_count`) is prone to merge conflicts in large projects.
  This does not affect analysis.
+ Add `tool/phan_repl_helpers.php`, a prototype tool that adds some functionality to `php -a`.
  It can be required by running `require_once 'path/to/phan/tool/phan_repl_helpers.php'` during an interactive session.

  - This replaces the readline code completion and adds autocomplete for `->` on global variables.
    This is currently buggy and very limited, and is missing some of the code completion functionality that is available in `php -a`.
    (And it's missing a lot of the code completion functionality from the language server)
  - This adds a global function `help($element_name_or_object)`. Run `help('help')` for usage and examples.
  - Future releases may advantage of Phan's parsing/analysis capabilities in more ways.
  - Several alternatives to the php shell already exist, such as [psysh](https://github.com/bobthecow/psysh).
    `tool/phan_repl_helpers.php` is an experiment in augmenting the interactive php shell, not an alternative shell.
+ Update progress bar during class analysis phase. (#4099)

New features (Analysis):
+ Support casting `iterable<SubClass>` to `iterable<BaseClass>` (#4089)
+ Change phrasing for `analyze` phase in `--long-progress-bar` with `--analyze-twice`
+ Add `PhanParamNameIndicatingUnused` and `PhanParamNameIndicatingUnusedInClosure`
  to indicate that using parameter names(`$unused*`, `$_`) to indicate to Phan that a parameter is unused is no longer recommended. (#4097)
  Suppressions or the `@param [Type] $param_name @unused-param` syntax can be used instead.
  PHP 8.0 will introduce named argument support.
+ Add a message to `PhanParamSignatureMismatch` indicating the cause of the issue being emitted. (#4103)
  Note that `PhanParamSignaturePHPDocMismatch*` and `PhanParamSignatureReal*` have fewer false positives.
+ Warn about invalid types in class constants. (#4104)
  Emit `PhanUndeclaredTypeClassConstant` if undeclared types are seen in phpdoc for class constants.
  Emit `PhanCommentObjectInClassConstantType` if object types are seen in phpdoc for class constants.
+ Warn about `iterable<UndeclaredClass>` containing undeclared classes. (#4104)

Language Server/Daemon mode:
+ Include PHP keywords such as `__FILE__`, `switch`, `function`, etc. in suggestions for code completions.

Plugins:
+ Make `DuplicateExpressionPlugin` warn if adjacent statements are identical. (#4074)
  New issue types: `PhanPluginDuplicateAdjacentStatement`.
+ Consistently make `PhanPluginPrintfNonexistentArgument` have critical severity. (#4080)
  Passing too few format string arguments (e.g. `printf("%s %s", "Hello,")`) will be an `ArgumentCountError` in PHP 8.

Bug fixes:
+ Fix false positive `PhanParamSignatureMismatch` issues (#4103)
+ Fix false positive `PhanParamSignaturePHPDocMismatchHasParamType` seen for magic method override of a real method with no real signature types. (#4103)

Jul 16 2020, Phan 3.1.0
-----------------------

New features (CLI, Config):
+ Add `--output-mode=verbose` to print the line of code which caused the issue to be emitted after the textual issue output.
  This is only emitted if the line is not whitespace, could be read, and does not exceed the config setting `max_verbose_snippet_length`.
+ Add `included_extension_subset` to limit Phan to using the reflection information to a subset of available extensions. (#4015)
  This can be used to make Phan warn about using constants/functions/classes that are not in the target environment or dependency list
  of a given PHP project/library.
  Note that this may cause issues if a class from an extension in this list depends on classes from another extension that is outside of this list.

New features (Analysis):
+ Don't emit `PhanTypeInvalidLeftOperandOfBitwiseOp` and other binary operation warnings for `mixed`
+ Emit `PhanIncompatibleRealPropertyType` when real property types are incompatible (#4016)
+ Change the way `PhanIncompatibleCompositionProp` is checked for. (#4024)
  Only emit it when the property was redeclared in an inherited trait.
+ Emit `PhanProvidingUnusedParameter` when passing an argument to a function with an optional parameter named `$unused*` or `$_`. (#4026)
  This can also be suppressed on the functionlike's declaration, and should be suppressed if this does not match the project's parameter naming.
  This is limited to functions with no overrides.
+ Emit `PhanParamTooFewInPHPDoc` when a parameter that is marked with `@phan-mandatory-param` is not passed in. (#4026)
  This is useful when needing to preserve method signature compatibility in a method override, or when a parameter will become mandatory in a future backwards incompatible release of a project.
+ Emit `PhanTypeMismatchArgumentProbablyReal` instead of `PhanTypeMismatchArgument` when the inferred real type of an argument has nothing in common with the phpdoc type of a user-defined function/method.
  This is usually a stronger indicator that the phpdoc parameter type is inaccurate/incomplete or the argument is incorrect.
  (Overall, fixing phpdoc errors may help ensure compatibility long-term if the library/framework being used moves to real types (e.g. php 8.0 union types) in the future.)

  **Note that Phan provides many ways to suppress issues (including the `--save-baseline=.phan/baseline.php` and `--load-baseline=.phan/baseline.php` functionality) in case
  the switch to `ProbablyReal` introduces too many new issues in your codebase.**
  (The new `ProbablyReal` issues are more severe than the original issue types.
  When they're suppressed, the original less severe issue types will also be suppressed)
+ Emit `PhanTypeMismatchReturnProbablyReal` instead of `PhanTypeMismatchReturn` when the inferred real return type has nothing in common with the declared phpdoc return type of a user-defined function/method. (#4028)
+ Emit `PhanTypeMismatchPropertyProbablyReal` instead of `PhanTypeMismatchProperty` when the inferred assigned property type has nothing in common with a property's declared phpdoc type. (#4029)
+ Emit `PhanTypeMismatchArgumentInternalProbablyReal` instead of `PhanTypeMismatchArgumentInternal` in a few more cases.
+ Be stricter about checking if callables/closures have anything in common with other types.
+ Preserve more specific phpdoc types when the php 8.0 `mixed` type is part of the real type set.
+ Also emit `PhanPluginUseReturnValueNoopVoid` when a function/method's return type is implicitly void (#4049)
+ Support `@param MyType $name one line description @unused-param` to suppress warnings about individual unused method parameters.
  This is a new alias of `@phan-unused-param`.
+ Support analyzing [PHP 8.0's match expression](https://wiki.php.net/rfc/match_expression_v2). (#3970)

Plugins:
+ Warn and skip checks instead of crashing when running `InlineHTMLPlugin` without the `tokenizer` extension installed. (#3998)
+ Support throwing `\Phan\PluginV3\UnloadablePluginException` instead of returning a plugin object in plugin files.
+ When a plugin registers for a method definition with `AnalyzeFunctionCallCapability`, automatically register the same closure for all classlikes using the same inherited definition of that method. (#4021)
+ Add `UnsafeCodePlugin` to warn about uses of `eval` or the backtick string shorthand for `shell_exec()`.
+ Add `DeprecateAliasPlugin` to mark known aliases such as `sizeof()` or `join()` as deprecated.
  Implement support for `--automatic-fix`.
+ Add `PHPDocInWrongCommentPlugin` to warn about using `/*` instead of `/**` with phpdoc annotations supported by Phan.

Miscellaneous
+ Update more unit tests for php 8.0.
+ Emit a warning and load an extremely limited polyfill for `filter_var` to parse integers/floats if the `filter` extension is not loaded.

Bug Fixes:
+ Make suppressions on trait methods/properties consistently apply to the inherited definitions from classes/traits using those traits.
+ Fix false positive where Phan would think that union types with real types containing `int` and other types had an impossible condition.
  Fix another false positive checking if `?A|?B` can cast to another union type.

Jul 03 2020, Phan 3.0.5
-----------------------

New features(CLI, Configs):
+ Add `-X` as an alias of `--dead-code-detection-prefer-false-positive`.

New features(Analysis):
+ Emit `PhanTypeInvalidLeftOperandOfBitwiseOp` and `PhanTypeInvalidRightOperandOfBitwiseOp` for argument types to bitwise operations other than `int|string`.
  (affects `^`, `|`, `&`, `^=`, `|=`, `&=`)

Bug fixes:
+ Fix false positives in php 8.0+ type checking against the real `mixed` type. (#3994)
+ Fix unintentionally enabling GC when the `pcntl` extension is not enabled. (#4000)
  It should only be enabled when running in daemon mode or as a language server.

Jul 01 2020, Phan 3.0.4
-----------------------

New features(Analysis):
+ Emit `PhanTypeVoidExpression` when using an expression returning `void` in places such as array keys/values.
+ More accurately infer unspecified types when closures are used with `array_map` (#3973)
+ Don't flatten array shapes and literal values passed to closures when analyzing closures. (Continue flattening for methods and global functions)
+ Link to documentation for internal stubs as a suggestion for undeclared class issues when Phan has type information related to the class in its signature files.
  See https://github.com/phan/phan/wiki/Frequently-Asked-Questions#undeclared_element
+ Properly render the default values if available(`ReflectionParameter->isDefaultValueAvailable()`) in php 8.0+.
+ Properly set the real union types based on reflection information for functions/methods in more edge cases.
+ Properly infer that union types containing the empty array shape are possibly empty after sorting (#3980)
+ Infer a more accurate real type set from unary ops `~`, `+`, and `-` (#3991)
+ Improve ability to infer assignments within true branch of complex expressions in conditions such as `if (A && complex_expression) { } else { }` (#3992)

Plugins:
+ Add `ShortArrayPlugin`, to suggest using `[]` instead of `array()` or `list()`
+ In `DuplicateExpressionPlugin`, emit `PhanPluginDuplicateExpressionAssignmentOperation` if `X = X op Y` is seen and it can be converted to `X op= Y` (#3985)
  (excluding `??=` for now)
+ Add `SimplifyExpressionPlugin`, to suggest shortening expressions such as `$realBool ? true : false` or `$realBool === false`
+ Add `RemoveDebugStatementPlugin`, to suggest removing debugging output statements such as `echo`, `print`, `printf`, `fwrite(STDERR, ...)`, `var_export(...)`, inline html, etc.
  This is only useful in applications or libraries that print output in only a few places, as a sanity check that debugging statements are not accidentally left in code.

Bug fixes:
+ Treat `@method static foo()` as an instance method returning the union type `static` (#3981)
  Previously, Phan treated it like a static method with type `void` based on an earlier phpdoc spec.
+ Fix the way that Phan inferred the `finally` block's exit status affected the `try` block. (#3987)

Jun 21 2020, Phan 3.0.3
-----------------------

New features(Analysis):
+ Include the most generic types when conditions such as `is_string()` to union types containing `mixed` (#3947)
+ More aggressively infer that `while` and `for` loop bodies are executed at least once in functions outside of other loops (#3948)
+ Infer the union type of `!$expr` from the type of `$expr` (#3948)
+ Re-enable `simplify_ast` by default in `.phan/config.php` (#3944, #3945)
+ Avoid false positives in `--constant-variable-detection` for `++`/`--`
+ Make `if (!$nullableValue) { }` remove truthy literal scalar values such as `'value'` and `1` and `1.0` when they're nullable
+ Emit `PhanTypeVoidArgument` when passing a void return value as a function argument (#3961)
+ Correctly merge the possible union types of pass-by-reference variables (#3959)
+ Improve php 8.0-dev shim support. Fix checking for array references and closure use references in php 8.0+.
+ More aggressively check if expression results should be used for conditionals and binary operators.

Plugins:
+ Add `ConstantVariablePlugin` to point out places where variables are read when they have only one possible scalar value. (#3953)
  This may help detect logic errors such as `$x === null ? json_encode($x) : 'default'` or code that could be simplified,
  but most issues it emits wouldn't be worth fixing due to hurting readability or being false positives.
+ Add `MergeVariableInfoCapability` for plugins to hook into ContextMergeVisitor and update data for a variable
  when merging the outcome of different scopes. (#3956)
+ Make `UseReturnValuePlugin` check if a method is declared as pure before using the dynamic checks based on percentage of
  calls where the return value is used, if that option is enabled.
+ In `DuplicateArrayKeyPlugin`, properly check for duplicate non-scalar cases.

Language Server/Daemon mode:
+ Fix bug where the Phan daemon would crash on the next request after analyzing a file outside of the project being analyzed,
  when pcntl was disabled or unavailable (#3954)

Bug fixes:
+ Fix `PhanDebugAnnotation` output for variables after the first one in `@phan-debug-var $a, $b` (#3943)
+ Use the correct constant to check if closure use variables are references in php 8.0+

Miscellaneous:
+ Update function signature stubs for the `memcache` PECL (#3841)

Jun 07 2020, Phan 3.0.2
-----------------------

New features(CLI, Configs):
+ Add `--dead-code-detection-prefer-false-positive` to run dead code detection,
  erring on the side of reporting potentially dead code even when it is possibly not dead.
  (e.g. when methods of unknown objects are invoked, don't mark all methods with the same name as potentially used)

New features(Analysis):
+ Fix false positive `PhanAbstractStaticMethodCall` (#3935)
  Also, properly emit `PhanAbstractStaticMethodCall` for a variable containing a string class name.

Plugins:
+ Fix incorrect check and suggestion for `PregRegexCheckerPlugin`'s warning if
  `$` allows an optional newline before the end of the string when the configuration includes
  `['plugin_config' => ['regex_warn_if_newline_allowed_at_end' => true]]`) (#3938)
+ Add `BeforeLoopBodyAnalysisCapability` for plugins to analyze loop conditions before the body (#3936)
+ Warn about suspicious param order for `str_contains`, `str_ends_with`, and `str_starts_with` in `SuspiciousParamOrderPlugin` (#3934)

Bug fixes:
+ Don't report unreferenced class properties of internal stub files during dead code detection
  (i.e. files in `autoload_internal_extension_signatures`).
+ Don't remove the leading directory separator when attempting to convert a file outside the project to a relative path.
  (in cases where the directory is different but has the project's name as a prefix)

Jun 04 2020, Phan 3.0.1
-----------------------

New features(Analysis):
+ Support analysis of php 8.0's `mixed` type (#3899)
  New issue types: `PhanCompatibleMixedType`, `PhanCompatibleUseMixed`.
+ Treat `static` and `false` like real types and emit more severe issues in all php versions.
+ Improve type inferences from negated type assertions (#3923)
  (analyze more expression kinds, infer real types in more places)
+ Warn about unnecessary use of `expr ?? null`. (#3925)
  New issue types: `PhanCoalescingNeverUndefined`.
+ Support PHP 8.0 non-capturing catches (#3907)
  New issue types: `PhanCompatibleNonCapturingCatch`.
+ Infer type of `$x->magicProp` from the signature of `__get`
+ Treat functions/methods that are only called by themselves as unreferenced during dead code detection.
+ Warn about `each()` being deprecated when the `target_php_version` is php 7.2+. (#2746)
  This is special cased because PHP does not flag the function itself as deprecated in `ReflectionFunction`.
  (PHP only emits the deprecation notice for `each()` once at runtime)

Miscellaneous:
+ Check for keys that are too long when computing levenshtein distances (when Phan suggests alternatives).

Plugins:
+ Add `AnalyzeLiteralStatementCapability` for plugins to analyze no-op string literals (#3911)
+ In `PregRegexCheckerPlugin`, warn if `$` allows an optional newline before the end of the string
  when configuration includes `['plugin_config' => ['regex_warn_if_newline_allowed_at_end' => true]]`) (#3915)
+ In `SuspiciousParamOrderPlugin`, warn if an argument has a near-exact name match for a parameter at a different position (#3929)
  E.g. warn about calling `foo($b)` or `foo(true, $this->A)` for `function foo($a = false, $b = false)`.
  New issue types: `PhanPluginSuspiciousParamPosition`, `PhanPluginSuspiciousParamPositionInternal`

Bug fixes:
+ Fix false positive `PhanTypeMismatchPropertyDefault` involving php 7.4 typed properties with no default
  and generic comments (#3917)
+ Don't remove leading directory separator when attempting to convert a file outside the project to a relative path.

May 09 2020, Phan 3.0.0
-----------------------

New features(CLI, Config):
+ Support `PHAN_COLOR_PROGRESS_BAR` as an environment variable to set the color of the progress bar.
  Ansi color names (e.g. `light_blue`) or color codes (e.g. `94`) can be used. (See src/Phan/Output/Colorizing.php)

New features(Analysis):
+ Infer that `foreach` keys and values of possibly empty iterables are possibly undefined after the end of a loop. (#3898)
+ Allow using the polyfill parser to parse internal stubs. (#3902)
  (To support newer syntax such as union types, trailing commas in parameter lists, etc.)

May 02 2020, Phan 3.0.0-RC2
-----------------------

Fix published GitHub release tag (used `master` instead of `v3`).

May 02 2020, Phan 3.0.0-RC1
-----------------------

Backwards incompatible changes:
+ Drop PHP 7.1 support.  PHP 7.1 reached its end of life for security support in December 2019.
  Many of Phan's dependencies no longer publish releases supporting php 7.1,
  which will likely become a problem running Phan with future 8.x versions
  (e.g. in the published phar releases).
+ Drop PluginV2 support (which was deprecated in Phan 2) in favor of PluginV3.
+ Remove deprecated classes and helper methods.

??? ?? 2020, Phan 2.7.3 (dev)
-----------------------

Bug fixes:
+ Fix handling of windows path separators in `phan_client`
+ Fix a crash when emitting `PhanCompatibleAnyReturnTypePHP56` or `PhanCompatibleScalarTypePHP56` for methods with no parameters.

May 02 2020, Phan 2.7.2
-----------------------

New features(CLI, Config):
+ Add a `--native-syntax-check=/path/to/php` option to enable `InvokePHPNativeSyntaxCheckPlugin`
  and add that php binary to the `php_native_syntax_check_binaries` array of `plugin_config`
  (treated here as initially being the empty array if not configured).

  This CLI flag can be repeated to run PHP's native syntax checks with multiple php binaries.

New features(Analysis):
+ Emit `PhanTypeInvalidThrowStatementNonThrowable` when throwing expressions that can't cast to `\Throwable`. (#3853)
+ Include the relevant expression in more issue messages for type errors. (#3844)
+ Emit `PhanNoopSwitchCases` when a switch statement contains only the default case.
+ Warn about unreferenced private methods of the same name as methods in ancestor classes, in dead code detection.
+ Warn about useless loops. Phan considers loops useless when the following conditions hold:

  1. Variables defined within the loop aren't used outside of the loop
     (requires `unused_variable_detection` to be enabled whether or not there are actually variables)
  2. It's likely that the statements within the loop have no side effects
     (this is only inferred for a subset of expressions in code)

     (Enabling the plugin `UseReturnValuePlugin` (and optionally `'plugin_config' => ['infer_pure_methods' = true]`) helps detect if function calls are useless)
  3. The code is in a functionlike scope.

  New issue types: `PhanSideEffectFreeForeachBody`, `PhanSideEffectFreeForBody`, `PhanSideEffectFreeWhileBody`, `PhanSideEffectFreeDoWhileBody`
+ Infer that previous conditions are negated when analyzing the cases of a switch statement (#3866)
+ Support using `throw` as an expression, for PHP 8.0 (#3849)
  (e.g. `is_string($arg) || throw new InvalidArgumentException()`)
  Emit `PhanCompatibleThrowException` when `throw` is used as an expression instead of a statement.

Plugins:
+ Emit `PhanPluginDuplicateCatchStatementBody` in `DuplicateExpressionPlugin` when a catch statement has the same body and variable name as an adjacent catch statement.
  (This should be suppressed in projects that support php 7.0 or older)
+ Add `PHP53CompatibilityPlugin` as a demo plugin to catch common incompatibilities with PHP 5.3. (#915)
  New issue types: `PhanPluginCompatibilityArgumentUnpacking`, `PhanPluginCompatibilityArgumentUnpacking`, `PhanPluginCompatibilityArgumentUnpacking`
+ Add `DuplicateConstantPlugin` to warn about duplicate constant names (`define('X', value)` or `const X = value`) in the same statement list.
  This is only recommended in projects with files with too many global constants to track manually.

Bug Fixes:
+ Fix a bug causing FQSEN names or namespaces to be converted to lowercase even if they were never lowercase in the codebase being analyzed (#3583)

Miscellaneous:
+ Replace `PhanTypeInvalidPropertyDefaultReal` with `TypeMismatchPropertyDefault` (emitted instead of `TypeMismatchProperty`)
  and `TypeMismatchPropertyDefaultReal` (#3068)
+ Speed up `ASTHasher` for floats and integers (affects code such as `DuplicateExpressionPlugin`)
+ Call `uopz_allow_exit(true)` if uopz is enabled when initializing Phan. (#3880)
  Running Phan with `uopz` is recommended against (unless debugging Phan itself), because `uopz` causes unpredictable behavior.
  Use stubs or internal stubs instead.

Apr 11 2020, Phan 2.7.1
-----------------------

New features(CLI, Configs):
+ Improve the output of `tool/make_stubs`. Use better defaults than `null`.
  Render `unknown` for unknown defaults in `tool/make_stubs` and Phan's issue messages.
  (`default` is a reserved keyword used in switch statements)

Bug Fixes:
+ Work around unintentionally using `symfony/polyfill-72` for `spl_object_id` instead of Phan's polyfill.
  The version used caused issues on 32-bit php 7.1 installations, and a slight slowdown in php 7.1.

Plugins:
+ PHP 8.0-dev compatibility fixes for `InvokePHPNativeSyntaxCheckPlugin` on Windows.
+ Infer that some new functions in PHP 8.0-dev should be used in `UseReturnValuePlugin`
+ Emit the line and expression of the duplicated array key or switch case (#3837)

Apr 01 2020, Phan 2.7.0
-----------------------

New features(CLI, Configs):
+ Sort output of `--dump-ctags=basic` by element type before sorting by file name (#3811)
  (e.g. make class and global function declarations the first tag type for a tag name)
+ Colorize the output of `phan_client` by default for the default and text output modes. (#3808)
  Add `phan --no-color` option to disable colors.
+ Warn about invalid CLI flags in `phan_client` (#3776)
+ Support representing more AST node types in issue messages. (#3783)
+ Make some issue messages easier to read (#3745, #3636)
+ Allow using `--minimum-severity=critical` instead of `--minimum-severity=10` (#3715)
+ Use better placeholders for parameter default types than `null` in issue messages and hover text (#3736)
+ Release `phantasm`, a prototype tool for assembling information about a codebase and aggressively optimizing it.
  Currently, the only feature is replacing class constants with their values, when safe to do so.
  More features (e.g. inlining methods, aggressively optimizing out getters/setters, etc.) are planned for the future.
  See `tool/phantasm --help` for usage.

New features(Analysis):
+ Improve analysis of php 7.4 typed properties.
  Support extracting their real union types from Reflection information.
  Infer the existence of properties that are not in `ReflectionClass->getPropertyDefaults()`
  due to being uninitialized by default.
+ Emit `PhanAbstractStaticMethodCall*` when calling an abstract static method statically. (#3799)
+ Emit `PhanUndeclaredClassReference` instead of `PhanUndeclaredClassConstant` for `MissingClass::class`.

Language Server/Daemon mode:
+ Catch exception seen when printing debug info about not being able to parse a file.
+ Warn when Phan's language server dependencies were installed for php 7.2+
  but the language server gets run in php 7.1. (phpdocumentor/reflection-docblock 5.0 requires php 7.2)
+ Immediately return cached hover text when the client repeats an identical hover request. (#3252)

Miscellaneous:
+ PHP 8.0-dev compatibility fixes, analysis for some new functions of PHP 8.0-dev.
+ Add `symfony/polyfill-php72` dependency so that symfony 5 will work better in php 7.1.
  The next Phan major release will drop support for php 7.1.

Mar 13 2020, Phan 2.6.1
-----------------------

New features(CLI, Configs):
+ Add a `--dump-ctags=basic` flag to dump a `tags` file in the project root directory. (https://linux.die.net/man/1/ctags)
  This is different from `tool/make_ctags_for_phan_project` - it has no external dependencies.

New features(Analysis):
+ Infer that the real type set of the key in `foreach ($arrayVar as $key => ...)` is definitely an `int|string`
  in places where Phan previously inferred the empty union type, improving redundant condition detection. (#3789)

Bug fixes:
+ Fix a crash in `phan --dead-code-detection` when a trait defines a real method and phpdoc `@method` of the same name (#3796)

Miscellaneous:
+ Also allow `netresearch/jsonmapper@^2.0` as a dependency when enforcing the minimum allowed version (#3801)

Mar 07 2020, Phan 2.6.0
-----------------------

New features(CLI, Configs):
+ Show empty union types as `(empty union type)` in issue messages instead of as an empty string.
+ Add a new CLI option `--analyze-twice` to run the analysis phase twice (#3743)

  Phan infers additional type information for properties, return types, etc. while analyzing,
  and this will help it detect more potential errors.
  (on the first run, it would analyze files before some of those types were inferred)
+ Add a CLI option `--analyze-all-files` to analyze all files, ignoring `exclude_analysis_file_list`.
  This is potentially useful if third party dependencies are missing type information (also see `--analyze-twice`).
+ Add `--dump-analyzed-file-list` to dump all files Phan would analyze to stdout.
+ Add `allow_overriding_vague_return_types` to allow Phan to add inferred return types to functions/closures/methods declared with `@return mixed` or `@return object`.
  This is disabled by default.

  When this is enabled, it can be disabled for individual methods by adding `@phan-hardcode-return-type` to the comment of the method.
  (if the method has any type declarations such as `@return mixed`)

  Previously, Phan would only add inferred return types if there was no return type declaration.
  (also see `--analyze-twice`)
+ Also emit the code fragment for the argument in question in the `PhanTypeMismatchArgument` family of issue messages (#3779)
+ Render a few more AST node kinds in code fragments in issue messages.

New features(Analysis):
+ Support parsing php 8.0 union types (and the static return type) in the polyfill. (#3419, #3634)
+ Emit `PhanCompatibleUnionType` and `PhanCompatibleStaticType` when the target php version is less than 8.0 and union types or static return types are seen. (#3419, #3634)
+ Be more consistent when warning about issues in values of class constants, global constants, and property defaults.
+ Infer key and element types from `iterator_to_array()`
+ Infer that modification of or reading from static properties all use the same property declaration. (#3760)
  Previously, Phan would track the static property's type separately for each subclass.
  (static properties from traits become different instances, in each class using the trait)
+ Make assignments to properties of the declaring class affect type inference for those properties when accessed on subclasses (#3760)

  Note that Phan is only guaranteed to analyze files once, so if type information is missing,
  the only way to ensure it's available is to add it to phpdoc (`UnknownElementTypePlugin` can help) or use `--analyze-twice`.
+ Make internal checks if generic array types are strict subtypes of other types more accurate.
  (e.g. `object[]` is not a strict subtype of `stdClass[]`, but `stdClass[]` is a strict subtype of `object[]`)

Plugins:
+ Add `UnknownClassElementAccessPlugin` to warn about cases where Phan can't infer which class an instance method is being called on.
  (To work correctly, this plugin requires that Phan use a single analysis process)
+ Add `MoreSpecificElementTypePlugin` to warn about functions/methods where the phpdoc/actual return type is vaguer than the types that are actually returned by a method. (#3751)
  This is a work in progress, and has a lot of false positives.
  (To work correctly, this plugin requires that Phan use a single analysis process)
+ Fix crash in `PrintfCheckerPlugin` when analyzing code where `fprintf()` was passed an array instead of a format string.
+ Emit `PhanTypeMissingReturnReal` instead of `PhanTypeMissingReturn` when there is a real return type signature. (#3716)
+ Fix bug running `InvokePHPNativeSyntaxCheckPlugin` on Windows when PHP binary is in a path containing spaces. (#3766)

Bug fixes:
+ Fix bug causing Phan to fail to properly recursively analyze parameters of inherited methods (#3740)
  (i.e. when the methods are called on the subclass)
+ Fix ambiguity in the way `Closure():T[]` and `callable():T[]` are rendered in error messages. (#3731)
  Either render it as `(Closure():T)[]` or `Closure():(T[])`
+ Don't include both `.` and `vendor/x/y/` when initializing Phan configs with settings such as `--init --init-analyze-dir=.` (#3699)
+ Be more consistent about resolving `static` in generators and template types.
+ Infer the iterable value type for `Generator<V>`. It was previously only inferred when there were 2 or more template args in phpdoc.
+ Don't let less specific type signatures such as `@param object $x` override the real type signature of `MyClass $x` (#3749)
+ Support PHP 7.4's `??=` null coalescing assignment operator in the polyfill.
+ Fix crash analyzing invalid nodes such as `2 = $x` in `RedundantAssignmentPlugin`.
+ Fix crash inferring type of `isset ? 2 : 3` with `--use-fallback-parser` (#3767)
+ Fix false positive unreferenced method warnings for methods from traits
  when the methods were referenced in base classes or interfaces of classes using those traits.

Language Server/Daemon mode:
+ Various performance improvements for the language server/daemon with or without pcntl (#3758, #3769, #3771)

Feb 20 2020, Phan 2.5.0
-----------------------

New Features(CLI):
+ Support using `directory_suppressions` in Phan baseline files in `--load-baseline`. (#3698)
+ Improve error message for warnings about Phan being unable to read files in the analyzed directory.

New Features(Analysis):
+ Instead of failing to parse intersection types in phpdoc entirely, parse them as if they were union types. (#1629)
  The annotations `@phan-param`, `@phan-return`, `@phan-var`, etc. can be used to override the regular phpdoc in the various cases where this behavior causes problems.

  **Future** Phan releases will likely about unsupported phpdoc (e.g. `int&string`) and have actual support for intersection types.
+ Emit `PhanUndeclaredConstantOfClass` (severity critical) for undeclared class constants instead of `PhanUndeclaredConstant` (severity normal)
  This should not be confused with `PhanUndeclaredClassConstant`, which already exists and refers to accessing class constants of classes that don't exist.
+ Emit the expression that's an invalid object with issue types such as `PhanTypeExpectedObject*`, `PhanTypeInvalidInstanceof` (#3717)
+ Emit `PhanCompatibleScalarTypePHP56` and `PhanCompatibleAnyReturnTypePHP56` for function signatures when `target_php_version` is `'5.6'` (#915)
  (This also requires that `backward_compatibility_checks` be enabled.)
+ Use more accurate line numbers for warnings about function parameters.
+ When `assume_real_types_for_internal_functions` is on *and* a function has a non-empty return type in Reflection,
  make Phan's known real type signatures override the real reflection return type information (useful when Phan infers `list<string>` and Reflection says `array`).
  Phan previously used the type from Reflection.
+ Normalize phpdoc parameter and return types when there is a corresponding real type in the signature. (#3725)
  (e.g. convert `bool|false|null` to `?bool`)

Plugins:
+ Add `SubscribeEmitIssueCapability` to detect or suppress issues immediately before they are emitted. (#3719)

Bug fixes:
+ Don't include issues that weren't emitted in the file generated by `--save-baseline` (#3719)
+ Fix incorrect file location for other definition in `PhanRedefinedClassReference` under some circumstances.
+ Fix incorrect issue name: `PhanCompatibleNullableTypePHP71` should be named `PhanCompatibleObjectTypePHP71`
+ Fix false positive `PhanPartialTypeMismatchProperty` when a php 7.4 typed property has a default expression value (#3725)

Feb 13 2020, Phan 2.4.9
-----------------------

New Features(Analysis):
+ Infer that `class_exists` implies the first argument is a class-string,
  and that `method_exists` implies the first argument is a class-string or an object. (#2804, #3058)

  Note that Phan still does not infer that the class or method actually exists.
+ Emit `PhanRedefineClass` on **all** occurrences of a duplicate class, not just the ones after the first occurrence of the class. (#511)
+ Emit `PhanRedefineFunction` on **all** occurrences of a duplicate function/method, not just the ones after the first.
+ Emit `PhanRedefinedClassReference` for many types of uses of user-defined classes that Phan has parsed multiple definitions of.
  Phan will not warn about internal classes, because the duplicate definition is probably a polyfill.
  (e.g. `new DuplicateClass()`, `DuplicateClass::someMethod()`)

Bug fixes:
+ Fix false positive `PhanParamSuspiciousOrder` for `preg_replace_callback` (#3680)
+ Fix false positive `PhanUnanalyzableInheritance` for renamed methods from traits. (#3695)
+ Fix false positive `PhanUndeclaredConstant` previously seen for inherited class constants in some parse orders. (#3706)
+ Fix uncaught `TypeError` converting `iterable<T>` to nullable (#3709)

Jan 25 2020, Phan 2.4.8
-----------------------

Bug fixes:
+ Fix bug introduced in 2.4.7 where there were more false positives when `--no-progress-bar` was used. (#3677)

Jan 22 2020, Phan 2.4.7
-----------------------

New features(CLI, Configs):
+ Add an environment variable `PHAN_NO_UTF8=1` to always avoid UTF-8 in progress bars.
  This may help with terminals or logs that have issues with UTF-8 output.
  Error messages will continue to include UTF-8 when part of the error.
+ Allow `phan --init` to complete even if composer.json has no configured `autoload` directories,
  as long as at least one directory or file was configured.
+ Add a setting `error_prone_truthy_condition_detection` that can be enabled to warn about error-prone truthiness/falsiness checks.  New issue types:
  - `PhanSuspiciousTruthyCondition` (e.g. for `if ($x)` where `$x` is `object|int`)
  - `PhanSuspiciousTruthyString` (e.g. for `?string` - `'0'` is also falsey in PHP)
+ Limit calculation of max memory usage to the **running** worker processes with `--processes N` (#3606)
+ Omit options that should almost always be on (e.g. `analyze_signature_compatibility`) from the output of `phan --init` (#3660)
+ Allow `phan --init` to create config file with `target_php_version` of `'7.4'` or `'8.0'` based on composer.json (#3671)

New Features(Analysis):
+ Infer that merging defined variables with possibly undefined variables is also possibly undefined. (#1942)
+ Add a fallback when some types of conditional check results in a empty union type in a loop:
  If all types assigned to the variable in a loop in a function are known,
  then try applying the condition to the union of those types. (#3614)
  (This approach was chosen because it needs to run only once per function)
+ Infer that assignment operations (e.g. `+=`) create variables if they were undefined.
+ Properly infer that class constants that weren't literal int/float/strings have real type sets in their union types.
+ Normalize union types of generic array elements after fetching `$x[$offset]`.
  (e.g. change `bool|false|null` to `?bool`)
+ Normalize union types of result of `??` operator.
+ Fix false positives in redundant condition detection for the real types of array accesses. (#3638, #3645, #3650)
+ Support the `non-empty-string` type in phpdoc comments (neither `''` nor `'0'`).
  Warn about redundant/impossible checks of `non-empty-string`.
+ Support the `non-zero-int` type in phpdoc comments. Infer it in real types and warn about redundant checks for zero/truthiness.
+ Support the the `non-empty-mixed` in phpdoc comments and in inferences.
+ Fix false positives possibly undefined variable warnings after conditions
  such as `if (X || count($x = []))`, `if (X && preg_match(..., $matches))`, etc.

Bug fixes:
+ Fix a crash analyzing assignment operations on `$GLOBALS` such as `$GLOBALS['var'] += expr;` (#3615)
+ Fix false positive `Phan[Possibly]UndeclaredGlobalVariable` after conditions such as `assert($var instanceof MyClass` when the variable was not assigned to within the file or previously analyzed files. (#3616)
+ Fix line number of 0 for some nodes when `simplify_ast` is enabled. (#3649)

Plugins:
+ Make Phan use the real type set of the return value of the function being analyzed when plugins return a union type without a real type set.

Maintenance:
+ Infer that `explode()` is possibly the empty list when `$limit` is possibly negative. (#3617)
+ Make Phan's code follow more PSR-12 style guidelines
  (`<?php` on its own line, `function(): T` instead of `function() : T`, declare visibility for class constants)
+ Internal: Check if strings are non-zero length in Phan's implementation instead of checking for variable truthiness.
  (`'0'` is falsey)
+ Show `null` as lowercase instead of uppercase (the way `var_export` renders it) in more places.

Dec 29 2019, Phan 2.4.6
-----------------------

New features(CLI, Configs):
+ Add more detailed instructions for installing dependencies new php installations on Windows without a php.ini
+ Handle being installed in a non-standard composer directory name (i.e. not `vendor`) (mentioned in #1612)

New Features(Analysis):
+ Improve inferred array shapes for multi-dimensional assignments or conditions on arrays
  (e.g. `$x['first']['second'] = expr` or `if (cond($x['first']['second']))`) (#1510, #3569)
+ Infer that array offsets are no longer possibly undefined after conditions such as `if (!is_null($x['offset']))`
+ Improve worst-case runtime when merging union types with many types (#3587)
+ Improve analysis of assignment operators. (#3597)
+ Infer `$x op= expr` and `++`/`--` operators have a literal value when possible, outside of loops. (#3250, #3248)
+ Move `PhanUndeclaredInterface` and `PhanUndeclaredTrait` warnings to the line number of the `use`/`implements`. (#2159)
+ Don't emit `PhanUndeclaredGlobalVariable` for the left side of `??`/`??=` in the global scope (#3601)
+ More consistently infer that variables are possibly undefined if they are not defined in all branches. (#1345, #1942)
+ Add new issue types for possibly undeclared variables: `PhanPossiblyUndeclaredVariable` and `PhanPossiblyUndeclaredGlobalVariable`.

Plugins:
+ Add `StrictLiteralComparisonPlugin` to warn about loose equality comparisons of constant string/int to other values. (#2310)

Bug fixes:
+ Fix false positive PhanTypePossiblyInvalidDimOffset seen after
  other array fields get added to an array shape by assignment or condition (#3579, #3569)
+ Properly extract the value of binary integer literals and binary/hex/octal float literals in the polyfill/fallback parser. (#3586)

Dec 10 2019, Phan 2.4.5
-----------------------

Plugins:
+ When adding a plugin overriding the return type of a method,
  make it affect all methods of descendant classlikes that inherited that method definition.

New Features(Analysis)
+ Infer that `!empty($x['field']...)` also implies `$x['field']` is non-falsey. (#3570)

Bug fixes:
+ Fix bug in native parsing of `AST_TYPE_UNION` (union type) nodes for PHP 8.0.0-dev.
+ Don't print duplicate entries for functions with alternate signatures in `tool/make_stubs`
+ Fix Error parsing internal template types such as `non-empty-list<string>` when using `Type::fromFullyQualifiedString()`.
+ Fix warnings about `password_hash()` algorithm constants with php 7.4 (#3560)
  `PASSWORD_DEFAULT` became null in php 7.4, and other constants became strings.

  Note that you will need to run Phan with both php 7.4 and a `target_php_version` of 7.4 to fix the errors.
+ Fix uncaught `AssertionError` when parsing `@return \\...` (#3573)

Nov 24 2019, Phan 2.4.4
-----------------------

New features(CLI, Configs):
+ When stderr is redirected a file or to another program, show an append-only progress bar by default. (#3514)
  Phan would previously disable the progress bar entirely by default.

  The new `--long-progress-bar` CLI flag can be used to choose this progress bar.

  (The `--no-progress-bar` CLI flag or the environment variable `PHAN_DISABLE_PROGRESS_BAR=1` can be used to disable this)
+ Treat `$var = $x['possibly undefined offset']` as creating a definitely defined variable,
  not a possibly undefined variable. (#3534)

  The config setting `convert_possibly_undefined_offset_to_nullable` controls
  whether the field type gets cast to the nullable equivalent after removing undefined.

New features(Analysis):
+ Emit `PhanPossiblyUndefinedArrayOffset` for accesses to array fields that are possibly undefined. (#3534)
+ Warn about returning/not returning in void/non-void functions.
  New issue types: `PhanSyntaxReturnValueInVoid`, `PhanSyntaxReturnExpectedValue`
+ Infer that `$var[$offset] = expr;`/`$this->prop[$offset] = expr;` causes that element to be non-null (#3546)
+ Emit `PhanEmptyForeachBody` when iterating over a type that isn't `Traversable` with an empty statement list.
+ Warn about computing `array_values` for an array that is already a list. (#3540)
+ Infer the real type is still an array after assigning to a field of an array.

Plugins:
+ In `DuplicateExpressionPlugin`, emit `PhanPluginDuplicateIfStatements`
  if the body for `else` is identical to the above body for the `if/elseif`.

Maintenance:
+ Support native parsing of `AST_TYPE_UNION` (union type) nodes for PHP 8.0.0-dev.
+ Reduce memory usage after the polyfill/fallback parser parses ASTs
  (when the final AST isn't cached on disk from a previous run)
+ Make the error message for missing `php-ast` contain more detailed instructions on how to install `php-ast`.

Nov 20 2019, Phan 2.4.3
-----------------------

New features(CLI, Configs):
+ Support `NO_COLOR` environment variable. (https://no-color.org/)
  When this variable is set, Phan's error message and issue text will not be colorized unless the CLI arg `--color` or `-c` is used.
  This overrides the `PHAN_ENABLE_COLOR_OUTPUT` setting.
+ Add `PHAN_DISABLE_PROGRESS_BAR` environment variable to disable progress bar by default unless the CLI arg `--progress-bar` is used.
+ Show an extra decimal digit of precision in the progress bar when the terminal is wide enough. (#3514)

New features(Analysis):
+ Make inferred real types more accurate for equality/identity/instanceof checks.
+ Combine array shape types into a single union type when merging variable types from multiple branches. (#3506)
  Do a better job of invalidating the real union type of fields of array shape types when the field is only checked/set on some code branches.
+ Make issue suggestions (and CLI suggestions) for completions of prefixes case-insensitive.
+ Support `@seal-properties` and `@seal-methods` as aliases of `@phan-forbid-undeclared-magic-properties` and `@phan-forbid-undeclared-magic-methods`
+ More aggressively infer real types of array destructuring(e.g. `[$x] = expr`) and accesses of array dimensions (e.g. `$x = expr[dim]`) (#3481)

  This will result in a few more false positives about potentially real redundant/impossible conditions and real type mismatches.
+ Fix false positives caused by assuming that the default values of properties are the real types of properties.
+ Infer that globals used in functions (`global $myGlobal;`) have unknown real types - don't emit warnings about redundant/impossible conditions. (#3521)

Plugins:
+ Also start checking if closures (and arrow functions) can be static in `PossiblyStaticMethodPlugin`
+ Add `AvoidableGetterPlugin` to suggest when `$this->prop` can be used instead of `$this->getProp()`.
  (This will suggest using the property instead of the getter method if there are no known method overrides of the getter. This is only checked for instance properties of `$this`)
+ Increase severity of `PhanPluginPrintfNonexistentArgument` to critical. It will become an ArgumentCountError in PHP 8.

Maintenance:
+ Bump minimum version of netresearch/jsonmapper to avoid php notices in the language server in php 7.4
+ Improve worst-case performance when analyzing code that has many possible combinations of array shapes.

Bug fixes:
+ Properly emit redundant and impossible condition warnings about uses of class constants defined as literal strings/floats/integers.
  (i.e. infer their real union types)
+ Fix false positive inference that `$x[0]` was `string` for `$x` of types such as `list<\MyClass>|string` (reported in #3483)
+ Consistently inherit analysis settings from parent classes recursively, instead of only inheriting them from the direct parent class. (#3472)
  (settings include presence of dynamic properties, whether undeclared magic methods are forbidden, etc.)
+ Don't treat methods that were overridden in one class but inherited by a different class as if they had overrides.
+ Fix a crash when running in php 8.0.0-dev due to Union Types being found in Reflection. (#3503)
+ Fix edge case looking up the `extends` class/interface name when the namespace is a `use` alias (#3494)

Nov 08 2019, Phan 2.4.2
-----------------------

New features(Analysis):
+ Emit `PhanTypeInvalidCallExpressionAssignment` when improperly assigning to a function/method's result (or a dimension of that result) (#3455)
+ Fix an edge case parsing `(0)::class` with the polyfill. (#3454)
+ Emit `PhanTypeInvalidDimOffset` for accessing any dimension on an empty string or an empty array. (#3385)
+ Warn about invalid string literal offsets such as `'str'[3]`, `'str'[-4]`, etc. (#3385)
+ Infer that arrays are non-empty and support array access from `isset($x[$offset])` (#3463)
+ Make `array_key_exists` imply argument is a `non-empty-array` (or an `object`). (#3465, #3469)
+ Make `isset($x[$offset])` imply argument is a `non-empty-array`, `object`, or `string`
  Make `isset($x['literal string'])` imply argument is a `non-empty-array` or `object`, and not a `string`.
+ Make `isset($x->prop)` imply `$x` is an `object`.
+ Make `isset($this->prop[$x])` imply `$this->prop` is not the empty array shape. (#3467)
+ Improve worst-case time of deduplicating unique types in a union type (#3477, suggested in #3475)

Maintenance:
+ Update function signature maps for internal signatures.

Bug fixes:
+ Fix false positive `PhanSuspiciousWeakTypeComparison` for `in_array`/`array_search`/`array_key_exists` with function arguments defaulting to `[]`

Nov 03 2019, Phan 2.4.1
-----------------------

New features(CLI, Configs):
+ Enable the progress bar by default, if `STDERR` is being rendered directly to a terminal.
  Add a new option `--no-progress-bar`.
+ Emit warnings about missing files in `file_list`, CLI args, etc. to `STDERR`. (#3434)
+ Clear the progress bar when emitting many types of warnings to STDERR.

New features(Analysis):
+ Suggest similarly named static methods and static properties for `PhanUndeclaredConstant` issues on class constants. (#3393)
+ Support `@mixin` (and an alias `@phan-mixin`) as a way to load public methods and public instance properties
  as magic methods and magic properties from another classlike. (#3237)

  Attempts to parse or analyze mixins can be disabled by setting `read_mixin_annotations` to `false` in your Phan config.
+ Support `@readonly` as an alias of the `@phan-read-only` annotation.
+ Also emit `PhanImpossibleTypeComparison` for `int === float` checks. (#3106)
+ Emit `PhanSuspiciousMagicConstant` when using `__METHOD__` in a function instead of a method.
+ Check return types and parameter types of global functions which Phan has signatures for,
  when `ignore_undeclared_functions_with_known_signatures` is `false` and `PhanUndeclaredFunction` is emitted. (#3441)

  Previously, Phan would emit `PhanUndeclaredFunction` without checking param or return types.
+ Emit `PhanImpossibleTypeComparison*` and `PhanSuspiciousWeakTypeComparison*`
  when `in_array` or `array_search` is used in a way that will always return false.
+ Emit `PhanImpossibleTypeComparison*` when `array_key_exists` is used in a way that will always return false.
  (e.g. checking for a string literal or negative key in a list, an integer in an array with known string keys, or anything in an empty array)
+ Add some missing function analyzers: Infer that `shuffle`, `rsort`, `natsort`, etc. convert arrays to lists.
  Same for `arsort`, `krsort`, etc.
+ Convert to `list` or `associative-array` in `sort`/`asort` in more edge cases.
+ Infer that `sort`/`asort` on an array (and other internal functions using references) returns a real `list` or `associative-array`.
  Infer that `sort`/`asort` on a non-empty array (and other internal functions using references) returns a real `non-empty-list` or `non-empty-associative-array`.
+ Infer that some array operations (`array_reduce`, `array_filter`, etc.) result in `array` instead of `non-empty-array` (etc.)

Bug fixes:
+ Fix a bug where global functions, closures, and arrow functions may have inferred values from previous analysis unintentionally
  left over in the original scope when analyzing that function again. (methods were unaffected)

Maintenance:
+ Clarify a warning message about "None of the files to analyze in /path/to/project exist"

Plugins:
+ Add a new plugin `RedundantAssignmentPlugin` to warn about assigning the same value a variable already has to that variable. (#3424)
  New issue types: `PhanPluginRedundantAssignment`, `PhanPluginRedundantAssignmentInLoop`, `PhanPluginRedundantAssignmentInGlobalScope`
+ Warn about alignment directives and more padding directives (`'x`) without width directive in `PrintfCheckerPlugin` (#3317)
+ Also emit `PhanPluginPrintfNoArguments` in cases when the format string could not be determined. (#3198)

Oct 26 2019, Phan 2.4.0
-----------------------

New features(CLI, Configs):
+ Support saving and loading baselines with `--save-baseline=.phan/baseline.php` and `--load-baseline=.phan/baseline.php`. (#2000)
  `--save-baseline` will save all pre-existing issues for the provided analysis settings to a file.
  When Phan is invoked later with `--load-baseline`, it will ignore any
  issue kinds in the files from `file_suppressions` in the baseline.

  This is useful for setting up analysis with Phan on a new project,
  or when enabling stricter analysis settings.

  Different baseline files can be used for different Phan configurations.
  (e.g. `.phan/baseline_deadcode.php` for runs with `--dead-code-detection`)

New features(Analysis):
+ Fix edge cases in checking if some nullable types were possibly falsey
  (`?true` and literal floats (e.g. `?1.1`))
+ Emit `PhanCoalescingNeverNull` instead of `PhanCoalescingNeverNullIn*`
  if it's impossible for the node kind to be null. (#3386)
+ Warn about array destructuring syntax errors (`[] = $arr`, `[$withoutKey, 1 => $withKey] = $arr`)
+ Return a clone of an existing variable if one already exists in Variable::fromNodeInContext. (#3406)
  This helps analyze `PassByReferenceVariable`s.
+ Don't emit PhanParamSpecial2 for min/max/implode/join with a single vararg. (#3396)
+ Properly emit PhanPossiblyInfiniteRecursionSameParams for functions with varargs.
+ Emit `PhanNoopNew` or `PhanNoopNewNoSideEffects` when an object is created with `new expr(...)` but the result is not used (#3410)
  This can be suppressed for all instances of a class-like by adding the `@phan-constructor-used-for-side-effects` annotation to the class's doc comment.
+ Emit `PhanPluginUseReturnValueInternalKnown` for unused results of function calls on the right-hand side of control flow operators (`??`/`?:`/`&&`/`||`) (#3408)

Oct 20 2019, Phan 2.3.1
-----------------------

New features(CLI, Configs):
+ Instead of printing the full help when Phan CLI args or configuration is invalid,
  print just the errors/warnings and instructions and `Type ./phan --help (or --extended-help) for usage.`
+ Add an option `--debug-signal-handler` that can be used to diagnose
  why Phan or a plugin is slow or hanging. (Requires the `pcntl` module)
  This installs a signal handler that response to SIGINT (aka Ctrl-C), SIGUSR1, and SIGUSR2.
+ Print a single backtrace in the crash reporter with the file, line, and arguments instead of multiple backtraces.
+ Emit a warning suggesting using `--long-option` instead when `-long-option[=value]` is passed in.
+ Change colorization of some error messages. Print some warnings to stderr instead of using `error_log()`.

New features(Analysis):
+ Emit `PhanTypeMismatchPropertyRealByRef` or `PhanTypeMismatchPropertyByRef`
  when potentially assigning an incompatible type to a php 7.4 typed property
  (or a property with a phpdoc type).
+ Warn about suspicious uses of `+` or `+=` on array shapes or lists. (#3364)
  These operator will prefer the fields from the left hand side,
  and will merge lists instead of concatenate them.
  New issue types: `PhanSuspiciousBinaryAddLists`, `PhanUselessBinaryAddRight`
+ Improvements to inferred types of `sort`, `array_merge`, etc. (#3354)
+ Fix bug allowing any array shape type to cast to a list.
+ Warn about unnecessary branches leading to identical return statements in pure functions, methods, and closures (#3383)
  This check is only run on pure methods.

  This requires that `UseReturnValuePlugin` be enabled and works best when `'plugin_config' => ['infer_pure_methods' => true]` is set.
+ Allow `list<X>` to cast to `array{0:X, 1?:X}` (#3390)
+ Speed up computing line numbers of diagnostics in the polyfill/fallback parser when there are multiple diagnostics.

Language Server/Daemon mode:
+ Reduce the CPU usage of the language server's main process when the `pcntl` module is used to fork analysis processes (Unix/Linux).
+ Speed up serializing large responses in language server mode (e.g. when a string has an unmatched quote).

Oct 13 2019, Phan 2.3.0
-----------------------

New features(CLI, Configs):
+ Limit --debug-emitted-issues to the files that weren't excluded from analysis.

New features(Analysis):
+ Add support for `list<T>` and `non-empty-list<T>` in phpdoc and in inferred values.
  These represent arrays with consecutive integer keys starting at 0 without any gaps (e.g. `function (string ...$args) {}`)
+ Add support for `associative-array<T>` and `non-empty-associative-array<T>` in phpdoc and in inferred values. (#3357)

  These are the opposite of `list<T>` and `non-empty-associative-list<T>`. `list` cannot cast to `associative-array` and vice-versa.
  These represent arrays that are unlikely to end up with consecutive integer keys starting at 0 without any gaps.
  `associative-array` is inferred after analyzing code such as the following:

  - Expressions such as `[$uid1 => $value, $uid2 => $value2]` with unknown keys
  - Unsetting an array key of a variable.
  - Adding an unknown array key to an empty array.
  - Certain built-in functions, such as `array_filter` or `array_unique`,
    which don't preserve all keys and don't renumber array keys.

  Note that `array<string, T>` is always treated like an associative array.

  However, `T[]` (i.e. `array<mixed, T>`) is not treated like `associative-array<mixed, T>` (i.e. `associative-array<T>`).
  Phan will warn about using the latter (`associative-array`) where a list is expected, but not the former (`array`).
+ Allow omitting keys from array shapes for sequential array elements
  (e.g. `array{stdClass, array}` is equivalent to `array{0:stdClass, 1:array}`).
+ Add array key of array shapes in the same field order that php would for assignments such as `$x = [10]; $x[1] = 11;`. (#3359)
+ Infer that arrays are non-empty after analyzing code such as `$x[expr] = expr` or `$x[] = expr`.
+ Infer that arrays are possibly empty after analyzing code such as `unset($x[expr]);`.
+ Fix false positives in redundant condition detection when the source union type contains the `mixed` type.

Oct 03 2019, Phan 2.2.13
------------------------

New features(CLI, Configs):
+ Always print 100% in `--progress-bar` after completing any phase of analysis.
  This is convenient for tools such as `tool/phoogle` that exit before starting the next phase.
+ Add GraphML output support to `DependencyGraphPlugin`.
  This allows `tool/pdep` output to be imported by Neo4j, Gephi and yEd
+ Add json output and import to `tool/pdep`
  For caching large graphs in order to generate multiple sub-graphs without re-scanning
+ Add setting `infer_default_properties_in_construct`.
  When this is enabled, infer that properties of `$this` are initialized to their default values at the start of `__construct()`. (#3213)
  (this is limited to instance properties which are declared in the current class (i.e. not inherited)).
  Off by default.
+ Add a config setting `strict_object_checking`. (#3262)
  When enabled, Phan will warn if some of the object types in the union type don't contain a property.
  Additionally, warn about definite non-object types when accessing properties.
  Also add `--strict-object-checking` to enable this setting.
+ Add CLI option `--debug-emitted-issues={basic,verbose}` to print the stack trace of when Phan emitted the issue to stderr.
  Useful for understanding why Phan emitted an issue.

New features(Analysis):
+ Disable `simplify_ast` by default.
  Phan's analysis of compound conditions and assignments/negations in conditions has improved enough that it should no longer be necessary.
+ Import more specific phpdoc/real array return types for internal global functions from opcache.
+ Emit `PhanUndeclaredVariable` and other warnings about arguments when there are too many parameters for methods. (#3245)
+ Infer real types of array/iterable keys and values in more cases.
+ Expose the last compilation warning seen when tokenizing or parsing with the native parser, if possible (#3263)
  New issue types: `PhanSyntaxCompileWarning`
  Additionally, expose the last compilation warning or deprecation notice seen when tokenizing in the polyfill.
+ Improve inference of when the real result of a binary operation is a float. (#3256)
+ Emit stricter warnings for more real type mismatches (#3256)
  (e.g. emit `PhanTypeMismatchArgumentReal` for `float->int` when `strict_types=1`, `'literal string'->int`, etc.)
+ Consistently infer that variadic parameters are arrays with integer keys. (#3294)
+ Improve types inferred when the config setting `enable_extended_internal_return_type_plugins` is enabled.
+ Speed up sorting the list of parsed files, and avoid unnecessary work in `--dump-parsed-file-list`.
+ Emit `PhanEmptyForeach` and `PhanEmptyYieldFrom` when iterating over empty arrays.
+ Infer that properties of `$this` are initialized to their default values at the start of `__construct()`. (#3213)
  (this is limited to instance properties which are declared in the current class (i.e. not inherited)).
  To disable this, set `infer_default_properties_in_construct` to false.
+ Improve analysis of conditions on properties of `$this`, such as `if (isset($this->prop['field1']['field2']))` (#3295)
+ Improve suggestions for `PhanUndeclaredFunction`.
  Properly suggest similar global functions for non-fully qualified calls in namespaces.
  Suggest `new ClassName()` as a suggestion for `ClassName()`.
+ Improve suggestions for global constants (`PhanUndeclaredConstant`).
  Suggest similar constant names case-insensitively within the same namespace or the global namespace.
+ Suggest obvious getters and setters for instance properties in `PhanAccessPropertyProtected` and `PhanAccessPropertyPrivate` (#2540)
+ When `strict_method_checking` is enabled,
  warn if some of the **object** types in the union type don't contain that method. (#3262)
+ Make stronger assumptions about real types of global constants.
  Assume that constants defined with `define(...)` can have any non-object as its real type,
  to avoid false positives in redundant condition detection.
+ Properly infer that parameter defaults and global constants will resolve to `null` in some edge cases.
+ Emit `PhanCompatibleDefaultEqualsNull` when using a different constant that resolves to null as the default of a non-nullable parameter. (#3307)
+ Emit `PhanPossiblyInfiniteRecursionSameParams` when a function or method calls itself with the same parameter values it was declared with (in a branch). (#2893)
  (This requires unused variable detection to be enabled, when there are 1 or more parameters)
+ Analyze complex conditions such as `switch (true)`, `if (($x instanceof stdClass) == false)`, etc. (#3315)
+ Add a `non-empty-array` type, for arrays that have 1 or more elements.
  This gets inferred for checks such as `if ($array)`, `if (!empty($array))` (checks on `count()` are not supported yet)
  (`non-empty-array<ValueT>` and `non-empty-array<KeyT, ValueT>` can also be used in phpdoc)
+ Support checking if comparisons of types with more than one possible literal scalar are redundant/impossible.
  Previously, Phan would only warn if both sides had exactly one possible scalar value.
  (e.g. warn about `'string literal' >= $nullableBool`)
+ Fix edge cases analyzing conditions on superglobals.
+ Be more consistent about when PhanTypeArraySuspiciousNullable is emitted, e.g. for `?mixed`, `array|null`, etc.
+ Fix false positive impossible condition for casting mixed to an array.

Language Server/Daemon mode:
+ Fix logged Error when language server receives `didChangeConfiguration` events. (this is a no-op)

Plugins:
+ Fix failure to emit `PhanPluginDescriptionlessComment*` when a description
  would be automatically generated from the property or method's return type. (#3265)
+ Support checking for duplicate phpdoc descriptions of properties or methods within a class in `HasPHPDocPlugin`.
  Set `'plugin_config' => ['has_phpdoc_check_duplicates' => true]` to enable these checks.
  (this skips deprecated methods/properties)
+ Implement `LoopVariableReusePlugin`, to detect reusing loop variables in nested loops. (#3045)
  (e.g. `for ($i = 0; $i < 10; $i++) { /* various code ... */ foreach ($array as $i => $value) { ... } }`)

Maintenance:
+ Make `\Phan\Library\None` a singleton in internal uses.
+ Normalize folders in the config file generated by `phan --init` in the vendor autoload directories.
+ Update internal element types and documentation maps.

Bug fixes:
+ Consistently deduplicate the real type set of union types (fixes some false positives in redundant condition detection).
+ Fix `\Phan\Debug`'s dumping representation of flags for `ast\AST_DIM`, `ast\AST_ARRAY_ELEM`,
  `ast\AST_PARAM`, `ast\AST_ASSIGN_OP` (`??=`), and `ast\AST_CONDITIONAL`.

  This affects some crash reporting and tools such as `internal/dump_fallback_ast.php`
+ Fix some infinite recursion edge cases caused parsing invalid recursive class inheritance. (#3264)

Sep 08 2019, Phan 2.2.12
------------------------

New features(CLI):
+ Improve error messages when the `--init-*` flags are provided without passing `--init`. (#3153)
  Previously, Phan would fail with a confusing error message.
+ New tool `tool/pdep` to visualize project dependencies - see `tool/pdep -h`
  (uses the internal plugin `DependencyGraphPlugin`)
+ Support running `tool/phoogle` (search for functions/methods by signatures) in Windows.
+ Add support for `--limit <count>` and `--progress-bar` to `tool/phoogle`.

New features(Analysis):
+ Support `@phan-immutable` annotation on class doc comments, to indicate that all instance properties are read-only.

  - Phan does not check if object fields of those immutable properties will change. (e.g. `$this->foo->prop = 'x';` is allowed)
  - This annotation does not imply that methods have no side effects (e.g. I/O, modifying global state)
  - This annotation does not imply that methods have deterministic return values or that methods' results should be used.

  `@phan-immutable` is an alias of `@phan-read-only`. `@phan-read-only` was previously supported on properties.
+ Support `@phan-side-effect-free` annotation on class doc comments,
  to indicate that all instances of the class are `@phan-immutable`
  and that methods of the class are free of external side effects. (#3182)

  - All instance properties are treated as read-only.
  - Almost all instance methods are treated as `@phan-side-effect-free` - their return values must be used.
    (excluding a few magic methods such as __wakeup, __set, etc.)
    This does not imply that they are deterministic (e.g. `rand()`, `file_get_contents()`, and `microtime()` are allowed)
+ Add `@phan-side-effect-free` as a clearer name of what `@phan-pure` implied for methods.
+ Fix false positives for checking for redundant conditions with `iterable` and `is_iterable`.
+ Properly infer real types for `is_resource` checks and other cases where UnionType::fromFullyQualifiedRealString() was used.
+ Avoid false positives for the config setting `'assume_real_types_for_internal_functions'`.
  Include all return types for many internal global functions for `--target-php-version` of `7.[0-4]`,
  including those caused by invalid arguments or argument counts.
+ Warn about division, modulo, and exponentiation by 0 (or by values that would cast to 0).
+ Fix a bug converting absolute paths to relative paths when the project directory is a substring of a subdirectory (#3158)
+ Show the real signature of the abstract method in PhanClassContainsAbstractMethod issues. (#3152)
+ Support analyzing php 7.3's `is_countable()`, and warn when the check is redundant or impossible (#3172)
+ Don't suggest `$this->prop` as an alternative to the undeclared variable `$prop` from a static method/closure. (#3174)
+ Make real return types of `Closure::bind()` and other closure helpers more accurate. (#3184)
+ Include `use($missingVar)` in suggestions for `PhanUndeclaredVariable` if it is defined outside the closure(s) scope.
  Also, suggest *hardcoded* globals such as `$argv`.
+ Warn about `$this instanceof self` and `$this instanceof static` being redundant.
+ Fix false positive `PhanInvalidConstantExpression` for php 7.4 argument unpacking (e.g. `function f($x = [1, ...SOME_CONST]) {}`)
+ Emit `PhanTypeMismatchArgumentInternalProbablyReal` when the real type of an argument doesn't match Phan's signature info for a function (#3199)
  (but there is no Reflection type info for the parameter)
  Continue emitting `PhanTypeMismatchArgumentInternal` when the real type info of the argument is unknown or is permitted to cast to the parameter.
+ Improve analysis of switch statements for unused variable detection and variable types (#3222, #1811)
+ Infer the value of `count()` for union types that have a real type with a single array shape.
+ Fix false positive `PhanSuspiciousValueComparisonInLoop` for value expressions that contain variables.
+ Warn about redundant condition detection in more cases in loops.
+ Warn about PHP 4 constructors such as `Foo::Foo()` if the class has no namespace and `__construct()` does not exist. (#740)
  Infer that defining `Foo::Foo()` creates the method alias `Foo::__construct()`.
+ Don't emit `PhanTypeMismatchArgumentReal` if the only cause of the mismatch is nullability of real types (if phpdoc types were compatible) (#3231)

Language Server/Daemon mode:
+ Ignore `'plugin_config' => ['infer_pure_methods' => true]` in language server and daemon mode. (#3220)
  That option is extremely slow and memory intensive.

Plugins:
+ If possible, suggest the types that Phan observed during analysis with `UnknownElementTypePlugin`. (#3146)
+ Make `InvalidVariableIssetPlugin` respect the `ignore_undeclared_variables_in_global_scope` option (#1403)

Maintenance:
+ Correctly check for the number of cpus/cores on MacOS in Phan's unit tests (#3143)

Bug fixes:
+ Don't parse `src/a.php` and `src\a.php` twice if both paths are generated from config or CLI options (#3166)

Aug 18 2019, Phan 2.2.11
------------------------

New features(Analysis):
+ Add a `@phan-real-return` annotation for functions/methods/closure (#3099),
  to make Phan act as if that method has the specified union type
  when analyzing callers for redundant conditions, etc. (if there was no real type).
  This can be used for multiple types, e.g. `@phan-real-return string|false`.
+ Improve union type inferred for clone() - It must be an object if clone() doesn't throw.
  Emit `PhanTypePossiblyInvalidCloneNotObject` for cloning possible non-objects when strict param checking is enabled.
+ Infer that `new $expr()` has a real type of object in all cases, not just common ones.
+ Improve real type inferred for `+(expr)`/`-(expr)`/`~(expr)` and warn about redundant conditions.
  This does not attempt to account for custom behavior for objects provided by PECL extensions.
+ Show argument names and types in issue messages for functions/methods for `PhanParamTooFew` and `PhanParamTooMany`.
+ Show more accurate columns for `PhanSyntaxError` for unexpected tokens in more cases.
+ Ignore scalar and null type casting config settings when checking for redundant or impossible conditions. (#3105)
+ Infer that `empty($x)` implies that the value of $x is null, an empty scalar, or the empty array.
+ Avoid false positives with `if (empty($x['first']['second']))` - Do not infer any types for the offset 'first' if there weren't any already. (#3112)
+ Avoid some bad inferences when using the value of expressions of the form `A || B`.
+ Improve redundant condition detection for empty/falsey/truthy checks, `self`, and internal functions building or processing arrays.
+ Include strings that are suffixes of variable names, classes, methods, properties, etc. in issue suggestions for undeclared elements. (#2342)
+ Emit `PhanTypeNonVarReturnByRef` when an invalid expression is returned by a function declared to return a reference.
+ Support manually annotating that functions/methods/closures are pure with `/** @phan-pure */`.
  This is automatically inherited by overriding methods.
  Also see `UseReturnValuePlugin` and `'plugin_config' => ['infer_pure_methods' => true]`.

Plugins:
+ In `UseReturnValuePlugin`, support inferring whether closures, functions, and methods are pure
  when `'plugin_config' => ['infer_pure_methods' => true]` is enabled.
  (they're expected to not have side effects and should have their results used)

  This is a best-effort heuristic.
  This is done only for the functions and methods that are not excluded from analysis,
  and it isn't done for methods that override or are overridden by other methods.

  Note that functions such as `fopen()` are not pure due to side effects.
  UseReturnValuePlugin also warns about those because their results should be used.

  Automatic inference of function purity is done recursively.
+ Add `EmptyMethodAndFunctionPlugin` to warn about functions/methods/closures with empty statement lists. (#3110)
  This does not warn about functions or methods that are deprecated, overrides, or overridden.
+ Fix false positive in InvalidVariableIssetPlugin for expressions such as `isset(self::$prop['field'])` (#3089)

Maintenance:
+ Add example vim syntax highlighting snippet for Phan's custom phpdoc annotations to `plugins/vim/syntax/phan.vim`
  This makes it easier to tell if annotations were correctly typed.

Bug fixes:
+ Don't scan over folders that would be excluded by `'exclude_file_regex'` while parsing. (#3088)
  That adds additional time and may cause unnecessary permissions errors.
+ Properly parse literal float union types starting with `0.`

Aug 12 2019, Phan 2.2.10
------------------------

New features(Analysis):
+ Add support for `@param MyClass &$x @phan-ignore-reference`,
  to make Phan ignore the impact of references on the passed in argument. (#3082)
  This can be used when the result should be treated exactly like the original type for static analysis.

Plugins:
+ In EmptyStatementListPlugin, warn about switch statements where all cases are no-ops. (#3030)

Bug fixes:
+ Fix infinite recursion seen when passing `void` to something expecting a non-null type. (#3085)
  This only occurs with some settings, e.g. when `null_casts_as_any_type` is true. (introduced in 2.2.9)

Aug 11 2019, Phan 2.2.9
-----------------------

New features(Analysis):
+ Emit the stricter issue type `PhanTypeMismatchReturnReal` instead of `PhanTypeMismatchReturn`
  when Phan infers that the real type of the returned expression is likely to cause a TypeError (accounting for `strict_types` in the file). (#403)
  See `internal/Issue-Types-Caught-by-Phan.md` for details on when it is thrown.
+ Emit the stricter issue type `PhanTypeMismatchArgumentReal` instead of `PhanTypeMismatchArgument`
  when Phan infers that the real type of the argument is likely to cause a TypeError at runtime (#403)
+ Support php 7.4 typed property groups in the polyfill/fallback parser.
+ Warn about passing properties with incompatible types to reference parameters (#3060)
  New issue types: `PhanTypeMismatchArgumentPropertyReference`, `PhanTypeMismatchArgumentPropertyReferenceReal`
+ Detect redundant conditions such as `is_array($this->array_prop)` on typed properties.
  Their values will either be a value of the correct type, or unset. (Reading from unset properties will throw an Error at runtime)
+ Emit `PhanCompatibleTypedProperty` if the target php version is less than 7.4 but typed properties are used.
+ Emit `PhanTypeMismatchPropertyReal` instead of `PhanTypeMismatchProperty` if the properties have real types that are incompatible with the inferred type of the assignment.
+ Stop warning about `(float) $int` being redundant - there are small differences in how ints and floats are treated by `serialize`, `var_export`, `is_int`, etc.
+ Treat all assignments to `$this->prop` in a scope the same way (for real, dynamic, and magic properties)
  Previously, Phan would not track the effects of some assignments to dynamic properties.
+ Make `unset($this->prop)` make Phan infer that the property is unset in the current scope (and treat it like null) (only affects `$this`). (#3025)
  Emit `PhanPossiblyUnsetPropertyOfThis` if the property is read from without setting it.
+ Don't emit `PhanTypeArraySuspiciousNull` when array access is used with the null coalescing operator. (#3032)
+ Don't emit `PhanTypeInvalidDimOffset` when array access is used with the null coalescing operator. (#2123)
+ Make Phan check for `PhanUndeclaredTypeProperty` suppressions on the property's doc comment, not the class. (#3047)
+ Make inferred real/phpdoc types for results of division more accurate.
+ Improve analysis of for loops and while loops.
  Account for the possibility of the loop iteration never occurring. (unless the condition is unconditionally true)
+ Fix some edge cases that can cause PhanTypeMismatchProperty (#3067, #1867)
  If there was a phpdoc or real type, check against that instead when emitting issues.
+ Analyze assignments to fields of properties of `$this` (e.g. `$this->prop[] = 'value';`)
  for correctness and for the new type combination. (#3059)
+ Infer that the `void` should be treated similarly to null
  (in addition to existing checks, it's redundant to compare them to null).
  Don't warn about `return null;` in functions/methods with phpdoc-only `@return void`.

Plugins:
+ Add `StrictComparisonPlugin`, which warns about the following issue types:

  1. Using `in_array` or `array_search` without specifying `$strict`. (`PhanPluginComparisonNotStrictInCall`)
  2. Using comparison or weak equality operators when both sides are possibly objects. (`PhanPluginComparisonObjectEqualityNotStrict`, `PhanPluginComparisonObjectOrdering`)
+ Don't warn in `EmptyStatementListPlugin` if a TODO/FIXME/"Deliberately empty" comment is seen around the empty statement list. (#3036)
  (This may miss some TODOs due to `php-ast` not providing the end line numbers)
  The setting `'plugin_config' => ['empty_statement_list_ignore_todos' => true]` can be used to make it unconditionally warn about empty statement lists.
+ Improve checks for UseReturnValuePlugin for functions where warning depend on their arg count (`call_user_func`, `trait`/`interface`/`class_exists`, `preg_match`, etc)

Bug fixes:
+ When a typed property has an incompatible default, don't infer the union type from the default. (#3024)
+ Don't emit `PhanTypeMismatchProperties` for assignments to dynamic properties. (#3042)
+ Fix false positive RedundantConditions analyzing properties of `$this` in the local scope. (#3038)
+ Properly infer that real type is always `int` (or a subtype) after the `is_int($var)` condition.
+ Emit `TypeMismatchUnpack*` for nullable key types of iterables if the union type didn't contain any int/mixed types. (fix logic error)

Jul 30 2019, Phan 2.2.8
-----------------------

New features(CLI):
+ Add heuristics to `tool/phoogle` to better handle `object`, and to include functions with nullable params in the results of searches for all functions. (#3014)

New features(Analysis):
+ Emit `PhanCompatibleImplodeOrder` when the glue string is passed as the second instead of the first argument (#2089)
+ Emit `PhanCompatibleDimAlternativeSyntax` when using array and string array access syntax with curly braces
  when using the polyfill parser or php 7.4+. (#2989)
+ Emit `PhanCompatibleUnparenthesizedTernary` for expressions such as `a ? b : c ? d : e`. (#2989)
  (when using the polyfill parser or php 7.4+)
+ Emit `PhanConstructAccessSignatureMismatch` when a constructor is less visible than the parent class's constructor
  and the target php version is 7.1 or older. (#1405)

Plugins:
+ Make `EmptyStatementListPlugin` check `if` statements with negated conditions (those were previously skipped because they were simplified).

Bug fixes:
+ Fix a crash analyzing a dynamic property by reference (introduced in 2.2.7) (#3020)

Jul 27 2019, Phan 2.2.7
-----------------------

New features(CLI, Configs):
+ Include columns with most (but not all) occurrences of `PhanSyntaxError`
  (inferred using the polyfill - these may be incorrect a small fraction of the time)

  When the error is from the native `php-ast` parser, this is a best guess at the column.

  `hide_issue_column` can be used to remove the column from issue messages.
+ Add `--absolute-path-issue-messages` to emit absolute paths instead of relative paths for the file of an issue. (#1640)
  Note that this does not affect files within the issue message.
+ Properly render the progress bar when Phan runs with multiple processes (#2928)
+ Add an HTML output mode to generate an unstyled HTML fragment.
  Example CSS styles can be generated with `internal/dump_html_styles.php`
+ Add a `light` color scheme for white backgrounds.

New features(Analysis):
+ Fix failure to infer real types when an invoked function or method had a phpdoc `@return` in addition to the real type.
+ Infer union type from all classes that an instance method could possibly be, not just the first type seen in the expression's union type. (#2988)
+ Preserve remaining real union types after negation of `instanceof` checks (e.g. to check for redundant conditions).
+ Warn about throwing from `__toString()` in php versions prior to php 7.4. (#2805)
+ Emit `PhanTypeArraySuspiciousNull` for code such as `null['foo']` (#2965)
+ If a property with no phpdoc type has a default of an empty array, assume that it's type can be any array (when reading it) until the first assignment is seen.
+ Attempt to analyze modifying dynamic properties by reference (e.g. `$var->$prop` when $prop is a variable with a known string)
+ For undeclared variables in the global scope, emit `PhanUndeclaredGlobalVariable` instead of `PhanUndeclaredVariable` to distinguish those from undeclared variables within functions/methods. (#1652)
+ Emit `PhanCompatibleSyntaxNotice` for notices such as the deprecated `(real)` cast in php 7.4, when the real parser is used (#3012)

Language Server/Daemon mode:
+ When `PhanSyntaxError` is emitted, make the start of the error range
  the column of the error instead of the start of the line.

Plugins:
+ Add `EmptyStatementListPlugin` to warn about empty statement lists involving if/elseif statements, try statements, and loops.
+ Properly warn about redundant `@return` annotations followed by other annotation lines in `PHPDocRedundantPlugin`.

Bug fixes:
+ Treat `Foo::class` as a reference to the class/interface/trait `Foo` (#2945)
+ Fix crash for `(real)` cast in php 7.4. (#3012)
+ Work around crash due to deprecation notices in composer dependencies in php 7.4

Jul 17 2019, Phan 2.2.6
-----------------------

New features(CLI, Configs):
+ Include files in completion suggestions for `-P`/`--plugin` in the [completion script for zsh](plugins/zsh/_phan).

Bug fixes:
+ Fix crash analyzing `&&` and `||` conditions with literals on both sides (#2975)
+ Properly emit `PhanParamTooFew` when analyzing uses of functions/methods where a required parameter followed an optional parameter. (#2978)

Jul 14 2019, Phan 2.2.5
-----------------------

New features(CLI, Configs):
+ Add `-u` as an alias of `--unused-variable-detection`, and `-t` as an alias of `--redundant-condition-detection`
+ Added a zsh completion script ([`plugins/zsh/_phan`](plugins/zsh/_phan) has installation instructions).
+ Added a bash completion script ([`plugins/bash/phan`](plugins/bash/phan) has installation instructions).

New features(Analysis):
+ Fix false positive `PhanSuspiciousValueComparisonInLoop` when both sides change in a loop. (#2919)
+ Detect potential infinite loops such as `while (true) { does_not_exit_loop(); }`. (Requires `--redundant-condition-detection`)
  New issue types: `PhanInfiniteRecursion`.
+ Track that the **real** type of an array variable is an array after adding fields to it (#2932)
  (affects redundant condition detection and unused variable detection)
+ Warn about adding fields to an unused array variable, if Phan infers the real variable type is an array. (#2933)
+ Check for `PhanInfiniteLoop` when the condition expression is omitted (e.g. `for (;;) {}`)
+ Avoid false positives in real condition checks from weak equality checks such as `if ($x == null) { if ($x !== null) {}}` (#2924)
+ Warn about `X ? Y : Y` and `if (cond1) {...} elseif (cond1) {...}` in DuplicateExpressionPlugin (#2955)
+ Fix failure to infer type when there is an assignment (or `++$x`, or `$x OP= expr`) in a condition (#2964)
  (e.g. `return ($obj = maybeObj()) instanceof stdClass ? $obj : new stdClass();`)
+ Warn about no-ops in for loops (e.g. `for ($x; $x < 10, $x < 20; $x + 1) {}`) (#2926)
+ Treat `compact('var1', ['var2'])` as a usage of $var1 and $var2 in `--unused-variable-detection` (#1812)

Bug fixes:
+ Fix crash in StringUtil seen in php 7.4-dev due to notice in `hexdec()` (affects polyfill/fallback parser).

Plugins:
+ Add `InlineHTMLPlugin` to warn about inline HTML anywhere in an analyzed file's contents.
  In the `plugin_config` config array, `inline_html_whitelist_regex` and `inline_html_blacklist_regex` can be used to limit the subset of analyzed files to check for inline HTML.
+ For `UnusedSuppressionPlugin`: `'plugin_config' => ['unused_suppression_whitelisted_only' => true]` will make this plugin report unused suppressions only for issues in `whitelist_issue_types`. (#2961)
+ For `UseReturnValuePlugin`: warn about unused results of function calls in loops (#2926)
+ Provide the `$node` causing the call as a 5th parameter to closures returned by `AnalyzeFunctionCallCapability->getAnalyzeFunctionCallClosuresStatic`
  (this can be used to get the variable/expression for an instance method call, etc.)

Maintenance:
+ Made `--polyfill-parse-all-element-doc-comments` a no-op, it was only needed for compatibility with running Phan with php 7.0.
+ Minor updates to CLI help for Phan.
+ Restart without problematic extensions unless the corresponding `PHAN_ALLOW_$extension` flag is set. (#2900)
  These include uopz and grpc (when Phan would use `pcntl_fork`) - Phan already restarts without Xdebug.
+ Fix `Debug::nodeToString()` - Make it use a polyfill for `ast\get_kind_name` if the php-ast version is missing or outdated.

Jul 01 2019, Phan 2.2.4
-----------------------

New features(CLI, Configs):
+ Warn if any of the files passed in `--include-analysis-file-list` don't exist.

New features(Analysis):
+ Reduce false positives inferring the resulting type of `$x++`, `--$x`, etc. (#2877)
+ Fix false positives analyzing variable modification in `elseif` conditions (#2878, #2860)
  (e.g. no longer emit `PhanRedundantCondition` analyzing `elseif ($offset = (int)$offset)`)
  (e.g. do a better job inferring variables set in complex `if` condition expressions)
+ Warn about suspicious comparisons (e.g. `new stdClass() <= new ArrayObject`, `2 >= $bool`, etc.) (#2892)
+ Infer real union types from function/method calls.
+ Don't emit the specialized `*InLoop` or `*InGlobalScope` issues for `--redundant-condition-detection`
  in more cases where being in a global or loop scope doesn't matter (e.g. `if (new stdClass())`)
+ Be more accurate about inferring real union types from array destructuring assignments. (#2901)
+ Be more accurate about inferring real union types from assertions that expressions are non-null. (#2901)
+ Support dumping Phan's internal representation of a variable's union type (and real union type) with `'@phan-debug-var $varName'` (useful for debugging)
+ Fix false positive `PhanRedundantCondition` analyzing `if ([$a] = (expr))` (#2904)
+ Warn about suspicious comparisons that are always true or always false, e.g. the initial check for `for ($i = 100; $i < 20; $i++)` (#2888)
+ Emit `PhanSuspiciousLoopDirection` when a for loop increases a variable, but the variable is checked against a maximum (or the opposite) (#2888)
  e.g. `for ($i = 0; $i <= 10; $i--)`
+ Emit critical errors for duplicate use for class/namespace, function, or constant (#2897)
  New issue types: `PhanDuplicateUseNormal`, `PhanDuplicateUseFunction`, `PhanDuplicateUseConstant`
+ Emit `PhanCompatibleUnsetCast` for uses of the deprecated `(unset)(expr)` cast. (#2871)
+ Emit `PhanDeprecatedClass`, `PhanDeprecatedTrait`, and `PhanDeprecatedInterface` on the class directly inheriting from the deprecated class, trait, or interface. (#972)
  Stop emitting that issue when constructing a non-deprecated class inheriting from a deprecated class.
+ Include the deprecation reason for user-defined classes that were deprecated (#2807)
+ Fix false positives seen when non-template class extends a template class (#2573)

Language Server/Daemon mode:
+ Fix a crash - always run the language server or daemon with a single analysis process, regardless of CLI or config settings (#2898)
+ Properly locate the defining class for `MyClass::class` when the polyfill/fallback is used.
+ Don't emit color in responses from the daemon or language server unless the CLI flag `--color` is passed in.

Maintenance:
+ Warn if running Phan with php 7.4+ when the installed php-ast version is older than 1.0.2.
+ Make the AST caches for dev php versions (e.g. 7.4.0-dev, 8.0.0-dev) depend on the date when that PHP version was compiled.
+ Make the polyfill support PHP 7.4's array spread operator (e.g. `[$a, ...$otherArray]`) (#2786)
+ Make the polyfill support PHP 7.4's short arrow functions (e.g. `fn($x) => $x*2`)
+ Fix parsing of `some_call(namespace\func_name())` in the polyfill

Jun 17 2019, Phan 2.2.3
-----------------------

New features(Analysis):
+ Reduce false positives about `float` not casting to `int` introduced in 2.2.2

Jun 17 2019, Phan 2.2.2
-----------------------

New features(Analysis):
+ Support inferring literal float types. Warn about redundant conditions with union types of floats that are always truthy/falsey.

Maintenance:
+ Support parsing PHP 7.4's numeric literal separator (e.g. `1_000_000`, `0xCAFE_F00d`, `0b0101_1111`) in the polyfill (#2829)

Bug fixes:
+ Fix a crash in the Phan daemon on Mac/Linux (#2881)

Jun 16 2019, Phan 2.2.1
-----------------------

New features(CLI, Configs):
+ When printing help messages for errors in `phan --init`, print only the related options.
+ Make `phan --init` enable `redundant_condition_detection` when the strictest init level is requested. (#2849)
+ Add `--assume-real-types-for-internal-functions` to make stricter assumptions about the real types of internal functions (for use with `--redundant-condition-detection`).
  Note that in PHP 7 and earlier, internal functions would return null/false for incorrect argument types/argument counts, so enabling this option may cause false positives.

New features(Analysis):
+ Reduce the number of false positives of `--redundant-condition-detection` for variables in loops
+ Warn about more types of expressions causing redundant conditions (#2534, #822).
+ Emit `PhanRedundantCondition` and `PhanImpossibleCondition` for `$x instanceof SomeClass` expressions.
+ Emit `PhanImpossibleCondition` for `is_array` and `is_object` checks.

Bug fixes:
+ Fix issue that would make Phan infer that a redundant/impossible condition outside a loop was in a loop.
+ Avoid false positives analyzing expressions within `assert()`.
+ Fix method signatures for php 7.4's `WeakReference`.
+ Fix false positives analyzing uses of `__call` and `__callStatic` (#702)
+ Fix false positive redundant conditions for casting `callable` to object types.

Jun 14 2019, Phan 2.2.0
-----------------------

New features(CLI, Configs):
+ Add `--color-scheme <scheme>` for alternative colors of outputted issues (also configurable via environment variable as `PHAN_COLOR_SCHEME=<scheme>`)
  Supported values: `default`, `vim`, `eclipse_dark`
+ Be consistent about starting parameter/variable names with `$` in issue messages.
+ Add `--redundant-condition-detection` to attempt to detect redundant conditions/casts and impossible conditions based on the inferred real expression types.

New features(Analysis):
+ New issue types: `PhanRedundantCondition[InLoop]`, `PhanImpossibleCondition[InLoop]` (when `--redundant-condition-detection` is enabled)
  (e.g. `is_int(2)` and `boolval(true)` is redundant, `empty(2)` is impossible).

  Note: This has many false positives involving loops, variables set in loops, and global variables.
  This will be split into more granular issue types later on.

  The real types are inferred separately (and more conservatively) from regular (phpdoc+real) expression types.

  (these checks can also be enabled with the config setting `redundant_condition_detection`)
+ New issue types: `PhanImpossibleTypeComparison[InLoop]` (when `--redundant-condition-detection` is enabled) (#1807)
  (e.g. warns about `$x = new stdClass(); assert($x !== null)`)
+ New issue types: `PhanCoalescingAlwaysNull[InLoop]`, `PhanCoalescingNeverNull[InLoop]` (when `--redundant-condition-detection` is enabled)
  (e.g. warns about `(null ?? 'other')`, `($a >= $b) ?? 'default'`)
+ Infer real return types from Reflection of php and the enabled extensions (affects `--redundant-condition-detection`)
+ Make Phan more accurately infer types for reference parameters set in conditionals.
+ Make Phan more accurately infer types after try-catch blocks.
+ Make Phan more accurately check if a loop may be executed 0 times.
+ Fix issue causing results of previous method analysis to affect subsequent analysis in some edge cases (#2857)
+ Support the type `callable-object` in phpdoc and infer it from checks such as `is_callable($var) && is_object($var)` (#1336)
+ Support the type `callable-array` in phpdoc and infer it from checks such as `is_callable($var) && is_array($var)` (#2833)
+ Fix false positives in more edge cases when analyzing variables with type `static` (e.g. `yield from $this;`) (#2825)
+ Properly emit `NonStaticCallToStatic` in more edge cases (#2826)
+ Infer that `<=>` is `-1|0|1` instead of `int`
+ Infer that eval with backticks is `?string` instead of `string`

Maintenance:
+ Add updates to the function/method signature map from Psalm and PHPStan.

Bug fixes:
+ Fix a crash that occurred when an expression containing `class-string<T>` became nullable.

01 Jun 2019, Phan 2.1.0
-----------------------

New features(CLI, Configs):
+ Add more options to configure colorized output. (#2799)

  The environment variable `PHAN_ENABLE_COLOR_OUTPUT=1` and the config setting `color_issue_messages_if_supported` can be used to enable colorized output by default
  for the default output mode (`text`) when the terminal supports it.

  This can be disabled by setting `PHAN_DISABLE_COLOR_OUTPUT=1` or by passing the flag `--no-color`.
+ Colorize output of `--help` and `--extended-help` when `--color` is used or the terminal supports it.
  This can be disabled by setting `PHAN_DISABLE_COLOR_OUTPUT=1` or by passing the flag `--no-color`.

New features(Analysis):
+ Support unary and binary expressions on literals/constants in conditions. (#2812)
  (e.g. `assert($x === -(1))` and `assert($x === 2+2)` now infer that $x is -1 and 4, respectively)
+ Infer that static variables with no default are `null`.
+ Improve control flow analysis of unconditionally true/false branches.
+ Improve analysis of some ways to initialize groups of static variables.
  e.g. `static $a = null; static $b = null; if ($a === null) { $a = $b = rand(0,10); } use($a, $b)`
  will now also infer that $b is non-null.
+ Infer from `return new static();` and `return $this;` that the return type of a method is `@return static`, not `@return self` (#2797)
  (and propagate that to inherited methods)
+ Fix some false positives when casting array types containing `static` to types containing the class or its ancestors. (#2797)
+ Add `PhanTypeInstantiateAbstractStatic` and `PhanTypeInstantiateTraitStaticOrSelf` as lower-severity warnings about `return new self()` and `return new static()` (#2797)
  (emitted in static methods of abstract classes)
+ Fix false positives passing `static` to other classes. (#2797)
+ Fix false positive seen when `static` implements `ArrayAccess` (#2797)

Language Server/Daemon mode:
+ Add `--language-server-min-diagnostics-delay-ms <ms>`, to work around race conditions in some language clients.

20 May 2019, Phan 2.0.0
-----------------------

New features(Analysis):
+ Add early support for PHP 7.4's typed properties. (#2314)
  (This is incomplete, and does not support inheritance, assignment, impossible conditions, etc.)
+ Change warnings about undeclared `$this` into a critical `PhanUndeclaredThis` issue. (#2751)
+ Fix the check for `PhanUnusedVariableGlobal` (#2768)
+ Start work on supporting analyzing PHP 7.4's unpacking inside arrays. (e.g. `[1, 2, ...$arr1, 5]`) (#2779)
  NOTE: This does not yet check all types of errors, some code is unmigrated, and the polyfill does not yet support this.
+ Improve the check for invalid array unpacking in function calls with iterable/Traversable parameters. (#2779)

Plugins:
+ Improve help messages for `internal/dump_fallback_ast.php` (this tool may be of use when developing plugins)

Bug fixes:
+ Work around issues parsing binary operators in PHP 7.4-dev.
  Note that the latest version of php-ast (currently 1.0.2-dev) should be installed if you are testing Phan with PHP 7.4-dev.

13 May 2019, Phan 2.0.0-RC2
-----------------------

New features(Analysis):
+ Support analysis of PHP 7.4's short arrow function syntax (`fn ($arg) => expr`) (#2714)
  (requires php-ast 1.0.2dev or newer)

  Note that the polyfill does not yet support this syntax.
+ Infer the return types of PHP 7.4's magic methods `__serialize()` and `__unserialize()`. (#2755)
  Improve analysis of return types of other magic methods such as `__sleep()`.
+ Support more of PHP 7.4's function signatures (e.g. `WeakReference`) (#2756)
+ Improve detection of unused variables inside of loops/branches.

Plugins:
+ Detect some new php 7.3 functions (`array_key_first`, etc.) in `UseReturnValuePlugin`.
+ Don't emit a `PhanNativePHPSyntaxCheckPlugin` error in `InvokePHPNativeSyntaxCheckPlugin` due to a shebang before `declare(strict_types=1)`
+ Fix edge cases running `PhanNativePHPSyntaxCheckPlugin` on Windows (in language server/daemon mode)

Bug fixes:
+ Analyze the remaining expressions in a statement after emitting `PhanTraitParentReference` (#2750)
+ Don't emit `PhanUndeclaredVariable` within a closure if a `use` variable was undefined outside of it. (#2716)

09 May 2019, Phan 2.0.0-RC1
-----------------------

New features(CLI, Configs):
+ Enable language server features by default. (#2358)
  `--language-server-disable-go-to-definition`, `--language-server-disable-hover`, and `--language-server-disable-completion`
  can be used to disable those features.

Backwards Incompatible Changes:
+ Drop support for running Phan with PHP 7.0. (PHP 7.0 reached its end of life in December 2018)
  Analyzing codebases with `--target-php-version 7.0` continues to be supported.
+ Require php-ast 1.0.1 or newer (or the absence of php-ast with `--allow-polyfill-parser`)
  Phan switched from using [AST version 50 to version 70](https://github.com/nikic/php-ast#ast-versioning).

Plugins:
+ Change `PluginV2` to `PluginV3`
  `PluginV2` and its capabilities will continue to work to make migrating to Phan 2.x easier, but `PluginV2` is deprecated and will be removed in Phan 3.

  `PluginV3` has the same APIs and capabilities as PluginV2, but uses PHP 7.1 signatures (`void`, `?MyClass`, etc.)
+ Third party plugins may need to be upgraded to support changes in AST version 70, e.g. the new node kinds `AST_PROP_GROUP` and `AST_CLASS_NAME`
+ Add `PHPDocToRealTypesPlugin` to suggest real types to replace (or use alongside) phpdoc return types.
  This does not check that the phpdoc types are correct.

  `--automatic-fix` can be used to automate making these changes for issues that are not suppressed.
+ Add `PHPDocRedundantPlugin` to detect functions/methods/closures where the doc comment just repeats the types in the signature.
  (or when other parts don't just repeat information, but the `@return void` at the end is redundant)
+ Add a `BeforeAnalyzePhaseCapability`. Unlike `BeforeAnalyzeCapability`, this will run after methods are analyzed, not before.

09 May 2019, Phan 1.3.4
-----------------------

Bug fixes:
+ Fix bug in Phan 1.3.3 causing polyfill parser to be used if the installed version of php-ast was older than 1.0.1.

08 May 2019, Phan 1.3.3
-----------------------

New features(CLI, Configs):
+ Make the progress bar guaranteed to display 100% at the end of the analysis phase (#2694)
  Print a newline to stderr once Phan is done updating the progress bar.
+ Add `maximum_recursion_depth` - This setting specifies the maximum recursion depth that
  can be reached during re-analysis.
  Default is 2.
+ Add `--constant-variable-detection` - This checks for variables that can be replaced with literals or constants. (#2704)
  This is almost entirely false positives in most coding styles, but may catch some dead code.
+ Add `--language-server-disable-go-to-definition`, `--language-server-disable-hover`, and `--language-server-disable-completion`
  (These are already disabled by default, but will be enabled by default in Phan 2.0)

New features(Analysis):
+ Emit `PhanDeprecatedClassConstant` for code using a constant marked with `@deprecated`.
+ When recursively inferring the return type of `BaseClass::method()` from its return statements,
  make that also affect the inherited copies of that method (`SubClass::method()`). (#2718)
  This change is limited to methods with no return type in the phpdoc or real signature.
+ Improve unused variable detection: Detect more unused variables for expressions such as `$x++` and `$x -= 2` (#2715)
+ Fix false positive `PhanUnusedVariable` after assignment by reference (#2730)
+ Warn about references, static variables, and uses of global variables that are probably unnecessary (never used/assigned to afterwards) (#2733)
  New issue types: `PhanUnusedVariableReference`, `PhanUnusedVariableGlobal`,  `PhanUnusedVariableStatic`
+ Warn about invalid AST nodes for defaults of properties and static variables. (#2732)
+ Warn about union types on properties that might have an incomplete suffix. (e.g. `/** @var array<int, */`) (#2708)

Plugins:
+ Add more forms of checks such as `$x !== null ? $x : null` to `PhanPluginDuplicateConditionalNullCoalescing` (#2691)

28 Apr 2019, Phan 1.3.2
-----------------------

New features(CLI, Configs):
+ Add `--debug`/`-D` flag to generate verbose debug output.
  This is useful when looking into poor performance or unexpected behavior (e.g. infinite loops or crashes).
+ Suggest similarly named plugins if `--plugin SomePluginName` refers to a built-in plugin that doesn't exist.
+ Add `assume_no_external_class_overrides` - When enabled, Phan will more aggressively assume class elements aren't overridden.
  - e.g. infer that non-final methods without return statements have type `void`.
  Disabled by default.

New features(Analysis):
+ Support locally tracking assignments to and conditionals on `$this->prop` inside of function scopes. (#805, #204)

  This supports only one level of nesting. (i.e. Phan will not track `$this->prop->subProp` or `$this->prop['field']`)

  Properties are deliberately tracked for just the variable `$this` (which can't be reassigned), and not other variables.
+ Fix false positives with dead code detection for internal stubs in `autoload_internal_extension_signatures`. (#2605)
+ Add a way to escape/unescape array shape keys (newlines, binary data, etc) (#1664)

  e.g. `@return array{\n\r\t\x01\\:true}` in phpdoc would correspond to `return ["\n\r\t\x01\\" => true];`

Plugins:
+ Add `FFIAnalysisPlugin` to avoid false positives in uses of PHP 7.4's `FFI\CData`  (#2659)
  (C data of scalar types may be read and assigned as regular PHP data. `$x = FFI::new(“int”); $x = 42;`)

  Note that this is only implemented for variables right now.

20 Apr 2019, Phan 1.3.1
-----------------------

New features(Analysis):
+ Fix false positive `PhanTypeMismatchReturnNullable` and `PhanTypeMismatchArgumentNullable` introduced in 1.3.0 (#2667)
+ Emit `PhanPossiblyNullTypeMismatchProperty` instead of `PhanTypeMismatchProperty` when assigning `?T`
  to a property expecting a compatible but non-nullable type.

  (The same issue was already emitted when the internal union type representation was `T|null` (not `?T`) and strict property type checking was enabled)

Plugins:
+ Add `PossiblyStaticMethodPlugin` to detect instance methods that can be changed to static methods (#2609)
+ Fix edge cases checking if left/right-hand side of binary operations are numbers in `NumericalComparisonPlugin`

19 Apr 2019, Phan 1.3.0
-----------------------

New features(Analysis):
+ Fix false positive `UnusedSuppression` when a doc comment suppresses an issue about itself. (#2571)
+ Improve analysis of argument unpacking with reference parameters, fix false positive `PhanTypeNonVarPassByRef` (#2646)
+ In issue descriptions and suggestions, replace invalid utf-8 (and literal newlines) with placeholders (#2645)
+ Suggest typo fixes in `PhanMisspelledAnnotation` for `@phan-*` annotations. (#2640)
+ Emit `PhanUnreferencedClass` when the only references to a class or its elements are within that class.
  Previously, it would fail to be emitted when a class referenced itself.
+ Emit `PhanUnusedPublicNoOverrideMethodParameter` for method parameters that are not overridden and are not overrides. (#2539)

  This is expected to have a lower false positive rate than `PhanUnusedPublicMethodParameter` because parameters
  might be unused by some of the classes overriding/implementing a method.

  Setting `unused_variable_detection_assume_override_exists` to true in `.phan/config.php` can be used to continue emitting the old issue names instead of `*NoOverride*` equivalents.
+ Warn about more numeric operations(+, /, etc) on unknown strings and non-numeric literal strings (#2656)
  The settings `scalar_implicit_cast` and `scalar_implicit_partial` affect this for the `string` union type but not for literals.
+ Improve types inferred from checks such as `if (is_array($var['field'])) { use($var['field']); }` and `if ($var['field'] instanceof stdClass) {...}` (#2601)
+ Infer that $varName is non-null and an object for conditions such as `if (isset($varName->field['prop']))`
+ Be more consistent about warning when passing `?SomeClass` to a parameter expecting non-null `SomeClass`.
+ Add `PhanTypeMismatchArgumentNullable*` and `PhanTypeMismatchReturnNullable` when the main reason the type check failed was nullability

  Previously, Phan would fail to detect that some nullable class instances were incompatible with the non-null expected types in some cases.
+ Improve analysis of negation of `instanceof` checks on nullable types. (#2663)

Language Server/Daemon mode:
+ Analyze new but unsaved files, if they would be analyzed by Phan once they actually were saved to disk.

Plugins:
+ Warn about assignments where the left-hand and right-hand side are the same expression in `DuplicateExpressionPlugin` (#2641)
  New issue type: `PhanPluginDuplicateExpressionAssignment`

Deprecations:
+ Print a message to stderr if the installed php-ast version is older than 1.0.1.
  A future major Phan version of Phan will probably depend on AST version 70 to support new syntax found in PHP 7.4.
+ Print a message to stderr if the installed PHP version is 7.0.
  A future major version of Phan will require PHP 7.1+ to run.

  Phan will still continue to support setting `target_php_version` to `'7.0'` and `--target-php-version 7.0` in that release.

Bug fixes:
+ Fix edge cases in how Phan checks if files are in `exclude_analysis_directory_list` (#2651)
+ Fix crash parsing comma in string literal in array shape (#2597)
  (e.g. `@param array{0:'test,other'} $x`)

06 Apr 2019, Phan 1.2.8
-----------------------

New features(CLI):
+ Fix edge cases initializing directory list and target versions of config files (#2629, #2160)

New features(Analysis):
+ Support analyzing `if (false !== is_string($var))` and similar complex conditions. (#2613)
+ Emit `PhanUnusedGotoLabel` for labels without a corresponding `goto` in the same function scope. (#2617)
  (note that Phan does not understand the effects of goto on control flow)
+ Don't emit `PhanUnreferencedClass` for anonymous classes. (#2604)
+ Detect undeclared types in phpdoc callables and closures. (#2562)
+ Warn about unreferenced PHPDoc `@property`/`@property-read`/`@property-write` annotations in `--dead-code-detection`.
  New issue types: `PhanWriteOnlyPHPDocProperty`, `PhanReadOnlyPHPDocProperty`, `PhanUnreferencedPHPDocProperty`.

Maintenance:
+ Make escaped string arguments fit on a single line for more issue types.
+ Rename `UseContantNoEffect` to `UseConstantNoEffect`.
+ Rename `AddressableElement::isStrictlyMoreVisibileThan()` to `isStrictlyMoreVisibleThan`.

Plugins:
+ Fix edge case where `WhitespacePlugin` would not detect trailing whitespace.
+ Detect `PhanPluginDuplicateSwitchCaseLooseEquality` in `DuplicateArrayKeyPlugin`. (#2310)
  Warn about cases of switch cases that are loosely equivalent to earlier cases, and which might get unexpectedly missed because of that. (e.g. `0` and `'foo'`)

Bug fixes:
+ Catch and handle "Cannot access parent when not in object context" when parsing global functions incorrectly using `parent` parameter type. (#2619)
+ Improve the performance of `--progress-bar` when the terminal width can't be computed by symfony. (#2634)

22 Mar 2019, Phan 1.2.7
-----------------------

New features(CLI,Configs)
+ Use a progress bar for `--progress-bar` on Windows instead of printing dots. (#2572)
  Use ASCII characters for the progress bar instead of UTF-8 if the code page isn't utf-8 or if Phan can't infer the terminal's code page (e.g. in PHP < 7.1)

Language Server/Daemon mode:
+ Make "Go to Definition" work when the constructor of a user-defined class is inherited from an internal class. (#2598)

Maintenance:
+ Update tolerant-php-parser version to 0.0.17
  (fix parsing of some edge cases, minor performance improvement, prepare to support php 7.4 in polyfill)
+ Use paratest for phpunit tests in Travis/Appveyor

Bug fixes:
+ Make the codeclimate plugin analyze the correct directory. Update the dependencies of the codeclimate plugin. (#2139)
+ Fix false positive checking for undefined offset with `$foo['strVal']` when strings are in the union type of `$foo` (#2541)
+ Fix crash in analysis of `call_user_func` (#2576)
+ Fix a false positive PhanTypeInvalidDimOffset for `unset` on array fields in conditional branches. (#2591)
+ Fix edge cases where types for variables inferred in one branch affect unrelated branches (#2593)

09 Mar 2019, Phan 1.2.6
-----------------------

New features(CLI,Configs)
+ Add config `enable_extended_internal_return_type_plugins` to more aggressively
  infer literal values for functions such as `json_decode`, `strtolower`, `implode`, etc. (disabled by default),
+ Make `--dead-code-detection` load `UnreachableCodePlugin` if that plugin isn't already loaded (#1824)
+ Add `--automatic-fix` to fix any issues Phan is capable of fixing
  (currently a prototype. Fixes are guessed based on line numbers).
  This is currently limited to:
  - unreferenced use statements on their own line (requires `--dead-code-detection`).
  - issues emitted by `WhitespacePlugin` (#2523)
  - unqualified global function calls/constant uses from namespaces (requires `NotFullyQualifiedUsagePlugin`)
    (will do the wrong thing for functions that are both global and in the same namespace)

New features(Analysis):
+ Make Phan infer more precise literal types for internal constants such as `PHP_EOF`.
  These depend on the PHP binary used to run Phan.

  In most cases, that shouldn't matter.
+ Emit `PhanPluginPrintfVariableFormatString` in `PrintfCheckerPlugin` if the inferred format string isn't a single literal (#2431)
+ Don't emit `PhanWriteOnlyPrivateProperty` with dead code detection when at least one assignment is by reference (#1658)
+ Allow a single hyphen between words in `@suppress issue-name` annotations (and `@phan-suppress-next-line issue-name`, etc.) (#2515)
  Note that CamelCase issue names are conventional for Phan and its plugins.
+ Emit `PhanCompatibleAutoload` when using `function __autoload() {}` instead of `spl_autoload_register() {}` (#2528)
+ Be more aggressive about inferring that the result is `null` when accessing array offsets that don't exist. (#2541)
+ Fix a false positive analyzing `array_map` when the closure has a dependent return type. (#2554)
+ Emit `PhanNoopArrayAccess` when an array field is fetched but not used (#2538)

Language Server/Daemon mode:
+ Fix an error in the language server on didChangeConfiguration
+ Show hover text of ancestors for class elements (methods, constants, and properties) when no summary is available for the class element. (#1945)

Maintenance
+ Don't exit if the AST version Phan uses (currently version 50) is deprecated by php-ast (#1134)

Plugins:
+ Write `PhanSelfCheckPlugin` for self-analysis of Phan and plugins for Phan. (#1576)
  This warns if too many/too few arguments are provided for the issue template when emitting an issue.
+ Add `AutomaticFixCapability` for plugins to provide fixes for issues for `--automatic-fix` (#2549)
+ Change issue messages for closures in `UnknownElementTypePlugin` (#2543)

Bug fixes:
+ Fix bug: `--ignore-undeclared` failed to properly ignore undeclared elements since 1.2.3 (#2502)
+ Fix false positive `PhanTypeInvalidDimOffset` for functions nested within other functions.
+ Support commas in the union types of parameters of magic methods (#2507)
+ Fix parsing `?(A|B|C)` (#2551)

27 Feb 2019, Phan 1.2.5
-----------------------

New features(Analysis):
+ Cache ASTs generated by the polyfill to disk by default, improving performance of the polyfill parser.
  (e.g. affects use cases where `php-ast` is not installed and `--use-polyfill-parser` is enabled).

  ASTs generated by the native parser (`php-ast`) are not cached.

  (For the language server/daemon mode, Phan stops reading from/writing to the cache after it finishes initializing)
+ Be more consistent warning about invalid callables passed to internal functions such as `register_shutdown_function` (#2046)
+ Add `@phan-suppress-next-next-line` to suppress issues on the line 2 lines below the comment. This is useful in block comments/doc comments. (#2470)
+ Add `@phan-suppress-previous-line` to suppress issues on the line above the comment. (#2470)
+ Detect `PhanRedefineClassConstant` and `PhanRedefineProperty` when class constants and properties are redefined. (#2492)

New features(CLI):
+ Add `--disable-cache` to disable the disk cache of ASTs generated by the polyfill.

Language Server/Daemon mode:
+ Show plaintext summaries of internal classes, functions, methods, constants, and properties when hover text is requested.
+ Show descriptions of superglobals and function parameters when hovering over a variable.

Maintenance
+ Render the constants in `PhanUndeclaredMagicConstant` as `__METHOD__` instead of `MAGIC_METHOD`

Plugins:
+ Add `WhitespacePlugin` to check for trailing whitespace, tabs, and carriage returns in PHP files.
+ Add `HandleLazyLoadInternalFunctionCapability` so that plugins can modify Phan's information about internal global functions when those functions are loaded after analysis starts.
+ Add `SuspiciousParamOrderPlugin` which guesses if arguments to functions are out of order based on the names used in the argument expressions.

  E.g. warns about invoking `function example($first, $second, $third)` as `example($mySecond, $myThird, $myFirst)`
+ Warn if too many arguments are passed to `emitIssue`, `emitPluginIssue`, etc. (#2481)

Bug fixes:
+ Support parsing nullable template types in PHPDoc (e.g. `@return ?T`)
+ Allow casting `null` to `?\MyClass<\Something>`.
+ Fix false positive PhanUndeclaredMagicConstant for `__METHOD__` and `__FUNCTION__` in function/method parameters (#2490)
+ More consistently emit `PhanParamReqAfterOpt` in methods (#1843).

18 Feb 2019, Phan 1.2.4
-----------------------

New features(Analysis):
+ Inherit more specific phpdoc template types even when there are real types in the signature. (#2447)
  e.g. inherit `@param MyClass<T>` and `@return MyClass<U>` from the
  ancestor class of `function someMethod(MyClass $x) : MyClass {}`.

  This is only done when each phpdoc type is compatible with the real signature type.
+ Warn about `@var Type` without a variable name in doc comments of function-likes (#2445)
+ Infer side effects of `array_push` and `array_unshift` on complex expressions such as properties. (#2365)
+ Warn when a non-string is used as a property name for a dynamic property access (#1402)
+ Don't emit `PhanAccessMethodProtected` for `if ($this instanceof OtherClasslike) { $this->protectedMethod(); }` (#2372)
  (This only applies to uses of the variable `$this`, e.g. in closures or when checking interfaces)

Plugins:
+ Warn about unspecialized array types of elements in UnknownElementTypePlugin. `mixed[]` can be used when absolutely nothing is known about the array's key or value types.
+ Warn about failing to use the return value of `var_export($value, true)` (and `print_r`) in `UseReturnValuePlugin` (#2391)
+ Fix plugin causing `InvalidVariableIssetPlugin` to go into an infinite loop for `isset(self::CONST['offset'])` (#2446)

Maintenance
+ Limit frames of stack traces in crash reports to 1000 bytes of encoded data. (#2444)
+ Support analysis of the upcoming php 7.4 `??=` operator (#2369)
+ Add a `target_php_version` option for PHP 7.4.
  This only affects inferred function signatures, and does not allow parsing newer syntax.

Bug fixes:
+ Fix a crash seen when parsing return typehint for `Closure` in a different case (e.g. `closure`) (#2438)
+ Fix an issue loading the autoloader multiple times when the `vendor` folder is not lowercase on case-sensitive filesystems (#2440)
+ Fix bug causing template types on methods to not work properly when inherited from a trait method.
+ Catch and warn when declaring a constant that would conflict with built in keywords (true/false/null) and prevent it from affecting inferences. (#1642)

10 Feb 2019, Phan 1.2.3
-----------------------

New features(CLI):
+ Add `-I <file_list>` as an alias of `--include-analysis-file-list <file>`.
+ Support repeating the include option (`-I <file_or_list> -I <file_or_list>`)
  and the exclude option (`-3 <file_or_list> -3 <file_or_list>`).

New features(Analysis):
+ Inherit more specific phpdoc types even when there are real types in the signature. (#2409)
  e.g. inherit `@param array<int,\stdClass>` and `@return MyClass[]` from the
  ancestor class of `function someMethod(array $x) : array {}`.

  This is only done when each phpdoc type is compatible with the real signature type.
+ Detect more expressions without side effects: `PhanNoopEmpty` and `PhanNoopIsset` (for `isset(expr)` and `empty(expr)`) (#2389)
+ Also emit `PhanNoopBinaryOperator` for the `??`, `||`, and `&&` operators,
  but only when the result is unused and the right-hand side has no obvious side effects. (#2389)
+ Properly analyze effects of a property/field access expression as the key of a `foreach` statement. (#1601)
+ Emit `PhanTypeInstantiateTrait` when calling `new TraitName()` (#2379)
+ Emit `PhanTemplateTypeConstant` when using `@var T` on a class constant's doc comment. (#2402)
+ Warn for invalid operands of a wider variety of binary operators (`/`, `/=`, `>>`, `<<=`, `-`, `%`, `**`, etc) (#2410)
  New issue types: `PhanTypeInvalidRightOperandOfIntegerOp` and `PhanTypeInvalidLeftOperandOfIntegerOp`.
  Also, mention the operator name in the issue message.

Language Server/Daemon mode:
+ Attempted fixes for bugs with issue filtering in the language server on Windows.
+ Add `--language-server-disable-output-filter`, which disables the language server filter to limit outputted issues
  to those in files currently open in the IDE.

Maintenance
+ Don't emit a warning to stderr when `--language-server-completion-vscode` is used.
+ Catch the rare RecursionDepthException in more places, improve readability of its exception message. (#2386)
+ Warn that php-ast 1.0.0 and older always crash with PHP 7.4-dev or newer.

Bug fixes:
+ Fix edge cases in checking if properties/methods are accessible from a trait (#2371)
+ Fix edge cases checking for `PhanTypeInstantiateInterface` and `PhanTypeInstantiateAbstract` (#2379)

Plugins:
+ Infer a literal string return value when calling `sprintf` on known literal scalar types in `PrintfCheckerPlugin`. (#2131)
+ Infer that `;@foo();` is not a usage of `foo()` in `UseReturnValuePlugin`. (#2412)
+ Implement `NotFullyQualifiedUsagePlugin` to warn about uses of global functions and constants that aren't fully qualified. (#857)

02 Feb 2019, Phan 1.2.2
-----------------------

New features(CLI):
+ Emit a warning to stderr if no files were parsed when Phan is invoked. (#2289)

New features(Analysis):
+ Add `@phan-extends` and `@extends` as an alias of `@inherits` (#2351)
+ Make checks such as `$x !== 'a literal'` (and `!=`) remove the literal string/int type from the union type. (#1789)

Language Server/Daemon mode:
+ Limit analysis results of the language server to only the currently open files. (#1722)
+ Limit analysis results of Phan daemon to just the requested files in **all** output formats (#2374)
  (not just when `phan_client` post-processes the output)
+ Make code completion immediately after typing `->` and `::` behave more consistently (#2343)
  Note: this fix only applies at the very last character of a line
+ Be more consistent about including types in hover text for properties (#2348)
+ Make "Go to Definition" on `new MyClass` go to `MyClass::__construct` if it exists. (#2276)
+ Support "Go to Definition" for references to global functions and global constants in comments and literal strings.
  Previously, Phan would only look for class definitions in comments and literal strings.
+ Fix a crash requesting completion results for some class names/global constants.

Maintenance:
+ Warn and exit immediately if any plugins are missing or invalid (instead of crashing after parsing all files) (#2099)
+ Emit warnings to stderr if any config settings seem to be the wrong type (#2376)
+ Standardize on logging to stderr.
+ Add more details about the call that crashed to the crash report.

Bug fixes:
+ Emit a warning and exit if `--config-file <file>` does not exist (#2271)
+ Fix inferences about `foreach ($arr as [[$nested]]) {...}` (#2362)
+ Properly analyze accesses of `@internal` elements of the root namespace from other parts of the root namespace. (#2366)
+ Consistently emit `UseNormalNoEffect` (etc.) when using names/functions/constants of the global scrope from the global scope.
+ Fix a bug causing incorrect warnings due to uses of global/class constants.

Plugins:
+ Add `UseReturnValuePlugin`, which will warn about code that calls a function/method such as `sprintf` or `array_merge` without using the return value.

  The list it uses is not comprehensive; it is a small subset of commonly used functions.

  This plugin can also be configured to automatically warn about failing to use a return value of **any** user-defined or internal function-like,
  when over 98% of the other calls in the codebase did use the return value.

18 Jan 2019, Phan 1.2.1
-----------------------

New features(CLI):
+ Add short flags: `-S` for `--strict-type-checking`, `-C` for `--color`, `-P` for `--plugin <plugin>`

New features(Analysis):
+ Infer that the result of `array_map` has integer keys when passed two or more arrays (#2277)
+ Improve inferences about the left-hand side of `&&` statements such as `$leftVar && (other_expression);` (#2300)
+ Warn about passing an undefined variable to a function expecting a reference parameter with a real, non-nullable type (#1344)
+ Include variables in scope as alternative suggestions for undeclared properties (#1680)
+ Infer a string literal when analyzing calls to `basename` or `dirname` on an expression that evaluates to a string literal. (#2323)
+ Be stricter about warning when literal int/string values are passed to incompatible scalar types when `scalar_implicit_cast` or `scalar_implicit_partial` are used. (#2340)

Maintenance:
+ End the output for `--output-mode <json>` with a newline.
+ Upgrade tolerant-php-parser, making the polyfill/fallback properly parse `$a && $b = $c` (#2180)
+ Add updates to the function/method signature map from Psalm and PHPStan.

Language Server/Daemon mode:
+ Add `--output-mode <mode>` to `phan_client`. (#1568)

  Supported formats: `phan_client` (default), `text`, `json`, `csv`, `codeclimate`, `checkstyle`, or `pylint`
+ Add `--color` to `phan_client` (e.g. for use with `--output-mode text`)
+ Add `--language-server-completion-vscode`. This is a workaround to make completion of variables and static properties work in [the Phan plugin for VS Code](https://github.com/tysonandre/vscode-php-phan)
+ Include Phan's signature types in hover text for internal and user-defined methods (instead of just the real types) (#2309)
  Also, show defaults of non-nullable parameters as `= default` instead of `= null`
+ Properly return a result set when requesting variable completion of `$` followed by nothing.
+ Fix code completion when `--language-server-analyze-only-on-save` is on. (#2327)

Plugins:
+ Add a new issue type to `DuplicateExpressionPlugin`: `PhanPluginBothLiteralsBinaryOp`. (#2297)

  (warns about suspicious expressions such as `null == 'a literal` in `$x ?? null == 'a literal'`)
+ Support `assertInternalType` in `PHPUnitAssertionPlugin` (#2290)
+ Warn when identical dynamic expressions (e.g. variables, function calls) are used as array keys in `DuplicateArrayKeyPlugin`
+ Allow plugins to include a `Suggestion` when calling `$this->emitIssue()`

05 Jan 2019, Phan 1.2.0
-----------------------

New features(Analysis):
+ Infer match keys of `$matches` for a wider range of regexes (e.g. non-capturing groups, named subgroups) (#2294)
+ Improve detection of invalid arguments in code implicitly calling `__invoke`.
+ Support extracting template types from more forms of `callable` types. (#2264)
+ Support `@phan-assert`, `@phan-assert-true-condition`, and `@phan-assert-false-condition`.
  Examples of side effects when this annotation is used on a function/method declaration:

  - `@phan-assert int $x` will assert that the argument to the parameter `$x` is of type `int`.
  - `@phan-assert !false $x` will assert that the argument to the parameter `$x` is not false.
  - `@phan-assert !\Traversable $x` will assert that the argument to the parameter `$x` is not `Traversable` (or a subclass)
  - `@phan-assert-true-condition $x` will make Phan infer that the argument to parameter `$x` is truthy if the function returned successfully.
  - `@phan-assert-false-condition $x` will make Phan infer that the argument to parameter `$x` is falsey if the function returned successfully.
  - This can be used in combination with Phan's template support.

  See [tests/plugin_test/src/072_custom_assertions.php](tests/plugin_test/src/072_custom_assertions.php) for example uses of these annotations.
+ Suggest typo fixes when emitting `PhanUnusedVariable`, if only one definition was seen. (#2281)
+ Infer that `new $x` is of the template type `T` if `$x` is `class-string<T>` (#2257)

Plugins:
- Add `PHPUnitAssertionPlugin`.
  This plugin will make Phan infer side effects of some of the helper methods PHPUnit provides within test cases.

  - Infer that a condition is truthy from `assertTrue()` and `assertNotFalse()` (e.g. `assertTrue($x instanceof MyClass)`)
  - Infer that a condition is null/not null from `assertNull()` and `assertNotNull()`
  - Infer class type of `$actual` from `assertInstanceOf(MyClass::class, $actual)`
  - Infer that `$actual` has the exact type of `$expected` after calling `assertSame($expected, $actual)`
  - Other methods aren't supported yet.

Bug fixes:
- Infer that some internal classes' properties (such as `\Exception->message`) are protected (#2283)
- Fix a crash running Phan without php-ast when no files were parsed (#2287)

30 Dec 2018, Phan 1.1.10
------------------------

New features(Analysis):
+ Add suggestions if to `PhanUndeclaredConstant` issue messages about undeclared global constants, if possible. (#2240)
  Suggestions include other global constants, variables, class constants, properties, and function names.
+ Warn about `continue` and `break` with no matching loop/switch scope. (#1869)
  New issue types: `PhanContinueOrBreakTooManyLevels`, `PhanContinueOrBreakNotInLoop`
+ Warn about `continue` statements targeting `switch` control structures (doing the same thing as a `break`) (#1869)
  New issue types: `PhanContinueTargetingSwitch`
+ Support inferring template types from array keys.
  int/string/mixed can be inferred from `array<TKey,\someType>` when `@template TKey` is in the class/function-like scope.
+ Phan can now infer template types from even more categories of parameter types in constructors and regular functions/methods. (#522)

  - infer `T` from `Closure(T):\OtherClass` and `callable(T):\OtherClass`
  - infer `T` from `array{keyName:T}`
  - infer `TKey` from `array<TKey,\OtherClass>` (as int, string, or mixed)

Bug fixes:
+ Refactor the way `@template` annotations are parsed on classes and function-likes to avoid various edge cases (#2253)
+ Fix a bug causing Phan to fail to analyze closures/uses of closures when used inline (e.g. in function calls)

27 Dec 2018, Phan 1.1.9
-----------------------

New features(Analysis):
+ Warn about `split` and other functions that were removed in PHP 7.0 by default. (#2235, #2236)
  (`target_php_version` can now be set to `'5.6'` if you have a PHP 5.6 project that uses those)
+ Fix a false positive `PhanUnreferencedConstant` seen when calling `define()` with a dynamic name. (#2245)
+ Support analyzing `@template` in PHPDoc of closures, functions and methods. (#522)

  Phan currently requires the template type to be part of the parameter type(s) as well as the return type.

  New issue types: `PhanTemplateTypeNotUsedInFunctionReturn`, `PhanTemplateTypeNotDeclaredInFunctionParams`
+ Make `@template` on classes behave more consistently. (#522)

  Phan will now check the union types of parameters instead of assuming that arguments will always occur in the same order and positions as `@template`.
+ Phan can now infer template types from more categories of parameter types in constructors and regular functions/methods. (#522)
  - `@param T[]`
  - `@param Closure():T`
  - `@param OtherClass<\stdClass,T>`

  - Note that this implementation is currently incomplete - Phan is not yet able to extract `T` from types not mentioned here (e.g. `array{0:T}`, `Generator<T>`, etc.)
+ Add `callable-string` and `class-string` types. (#1346)
  Warn if an invalid/undefined callable/class name is passed to parameters declared with those exact types.
+ Infer a more accurate literal string for the `::class` constant.

  Additionally, support inferring that a function/method will return instances of the passed in class name, when code has PHPDoc such as the following:

  ```
  /**
   * @template T
   * @param class-string<T> $className
   * @return T
   */
  ```

Plugins:
+ Detect more possible duplicates in `DuplicateArrayKeyPlugin`

Language Server/Daemon mode:
+ Be more consistent about how return types in methods (of files that aren't open) are inferred.

Bug fixes:
+ Fix a bug parsing the CLI option `--target-php-version major.minor` (Phan will now correctly set the `target_php_version` config setting)
+ Fix type inferences of `$x['offset'] = expr` in a branch, when outside of that branch. (#2241)

15 Dec 2018, Phan 1.1.8
-----------------------

New features(Analysis):
+ Infer more accurate types for return values/expected arguments of methods of template classes.
+ Support template types in magic methods and properties. (#776, related to #497)
+ Emit `PhanUndeclaredMagicConstant` when using a magic constant in a scope that doesn't make sense.
  Infer more accurate literal strings for some magic constants.

Bug fixes:
+ Fix a crash when an empty scalar value was passed to a function with variadic arguments (#2232)

08 Dec 2018, Phan 1.1.7
-----------------------

Maintenance:
+ Improve checks for empty/invalid FQSENs.
  Also, replace `PhanTypeExpectedObjectOrClassNameInvalidName` with `PhanEmptyFQSENInClasslike` or `PhanInvalidFQSENInClasslike`.

Bug fixes:
+ Fix uncaught crash on startup analyzing `class OCI-Lob` from oci8 (#2222)

08 Dec 2018, Phan 1.1.6
-----------------------

New features(Analysis):
+ Add suggestions to `PhanUndeclaredFunction` for functions in other namespaces
  and similarly named functions in the same namespace.
+ Add issue types `PhanInvalidFQSENInCallable` and `PhanInvalidFQSENInClasslike`
+ Properly analyze closures generated by `Closure::fromCallable()` on a method.
+ Emit `PhanDeprecatedCaseInsensitiveDefine` when define is used to create case-insensitive constants (#2213)

Maintenance:
+ Increase the default of the config setting `suggestion_check_limit` from 50 to 1000.
+ Shorten help messages for `phan --init` (#2162)

Plugins:
+ Add a prototype tool `tool/dump_markdown_preview`,
  which can be used to preview what description text Phan parses from a doc comment
  (similar to the language server's hover text)

Bug fixes:
+ Infer more accurate types after asserting `!empty($x)`
+ Fix a crash seen when analyzing anonymous class with 0 args
  when checking if PhanInfiniteRecursion should be emitted (#2206)
+ Fix a bug causing Phan to fail to warn about nullable phpdoc types
  replacing non-nullable param/return types in the real signature.
+ Infer the correct type for the result of the unary `+` operator.
  Improve inferences when `+`/`-` operators are used on string literals.
+ Fix name inferred for global constants `define()`d within a namespace (#2207).
  This now properly treats the constant name as being fully qualified.
+ Don't emit PhanParamSignatureRealMismatchReturnType for a return type of `T` replacing `?T`,
  or for `array` replacing `iterable` (#2211)

29 Nov 2018, Phan 1.1.5
-----------------------

Language Server:
+ Fix a crash in the Language Server when pcntl is not installed or enabled (e.g. on Windows) (#2186)

27 Nov 2018, Phan 1.1.4
-----------------------

New features(Analysis):
+ Preserve original descendent object types after type assertions, when original object types are all subtypes
  (e.g. infer `SubClass` for `$x = rand(0,1) ? new SubClass() : false; if ($x instanceof BaseClass) { ... }`)

Maintenance:
+ Emit `UnusedPluginSuppression` on `@phan-suppress-next-line` and `@phan-file-suppress`
  on the same line as the comment declaring the suppression. (#2167, #1731)
+ Don't emit `PhanInvalidCommentForDeclarationType` (or attempt to parse) unknown tags that have known tags as prefixes  (#2156)
  (e.g. `@param-some-unknown-tag`)

Bug fixes:
+ Fix a crash when analyzing a nullable parameter of type `self` in traits (#2163)
+ Properly parse closures/generic arrays/array shapes when inner types also contain commas (#2141)
+ Support matching parentheses inside closure params, recursively. (e.g. `Closure(int[],Closure(int):bool):int[]`)
+ Don't warn about properties being read-only when they might be modified by reference (#1729)

20 Nov 2018, Phan 1.1.3
-----------------------

New features(CLI):
+ Warn when calling method on union types that are definitely partially invalid. (#1885)
  New config setting: `--strict-method-checking` (enabled as part of `--strict-type-checking`)
  New issue type: `PhanPossiblyNonClassMethodCall`
+ Add a prototype tool `tool/phoogle`, which can be used to search for function/method signatures in user-declared and internal functions/methods.
  E.g. to look for functions that return a string, given a string and an array:
  `/path/phan/tool/phoogle 'string -> array -> string`

New features(Analysis):
+ Add a heuristic check to detect potential infinite recursion in a functionlike calling itself (i.e. stack overflows)
  New issue types: `PhanInfiniteRecursion`
+ Infer literal integer values from expressions such as `2 | 1`, `2 + 2`, etc.
+ Infer more accurate array shapes for `preg_match_all` (based on existing inferences for `preg_match`)
+ Make Phan infer union types of variables from switch statements on variables (#1291)
  (including literal int and string types)
+ Analyze simple assertions on `get_class($var)` of various forms (#1977)
  Examples:
  - `assert(get_class($x) === 'someClass')`
  - `if (get_class($x) === someClass::class)`
  - `switch (get_class($x)) {case someClass::class: ...}`
+ Warn about invalid/possibly invalid callables in function calls.
  New issue types: `PhanTypeInvalidCallable`, `PhanTypePossiblyInvalidCallable` (the latter check requires `--strict-method-checking`)
+ Reduce false positives for a few functions (such as `substr`) in strict mode.
+ Make Phan infer that variables are not null/false from various comparison expressions, e.g. `assert($x > 0);`
+ Detect invalid arguments to `++`/`--` operators (#680).
  Improve the analysis of the side effects of `++`/`--` operators.
  New issue type: `PhanTypeInvalidUnaryOperandIncOrDec`

Plugins:
+ Add `BeforeAnalyzeCapability`, which will be executed once before starting the analysis phase. (#2086)

Bug fixes:
+ Fix false positives analyzing `define()` (#2128)
+ Support declaring instance properties as the union type `static` (#2145)
  New issue types: `PhanStaticPropIsStaticType`
+ Fix a crash seen when Phan attempted to emit `PhanTypeArrayOperator` for certain operations (#2153)

05 Nov 2018, Phan 1.1.2
-----------------------

New features(CLI):
+ Make `phan --progress-bar` fit within narrower console widths. (#2096)
  (Make the old width into the new **maximum** width)
  Additionally, use a gradient of shades for the progress bar.

New features(Analysis):
+ Warn when attempting to read from a write-only real/magic property (or vice-versa) (#595)

  New issue types: `PhanAccessReadOnlyProperty`, `PhanAccessReadOnlyMagicProperty`, `PhanAccessWriteOnlyProperty`, `PhanAccessWriteOnlyMagicProperty`

  New annotations: `@phan-read-only` and `@phan-write-only` (on its own line) in the doc comment of a real property.
+ Warn about use statements that are redundant. (#2048)

  New issue types: `PhanUseConstantNoEffect`, `PhanUseFunctionNoEffect`, `PhanUseNormalNamespacedNoEffect`, `PhanUseNormalNoEffect`

  By default, this will only warn about use statements made from the global namespace, of elements also in the global namespace.
  To also warn about redundant **namespaced** uses of classes/namespaces (e.g. `namespace Foo; use Foo\MyClass;`), enable `warn_about_redundant_use_namespaced_class`
+ Warn when using a trait as a real param/return type of a method-like (#2007)
  New issue types: `PhanTypeInvalidTraitParam`, `PhanTypeInvalidTraitReturn`
+ Improve the polyfill/fallback parser's heredoc and nowdoc lexing (#1537)
+ Properly warn about an undefined variable being passed to `array_shift` (it expects an array but undefined is converted to null) (related to fix for #2100)
+ Stop adding generic int/string to the type of a class property when the doc comment mentions only literal int/string values (#2102)
  (e.g. `@var 1|2`)
+ Improve line number of warning about extra comma in arrays (i.e. empty array elements). (#2066)
+ Properly parse [flexible heredoc/nowdoc syntaxes](https://wiki.php.net/rfc/flexible_heredoc_nowdoc_syntaxes) that were added in PHP 7.3 (#1537)
+ Warn about more invalid operands of the binary operators `^`,`/`,`&` (#1825)
  Also, fix cases where `PhanTypeArrayOperator` would not be emitted.
  New issue types: `PhanTypeInvalidBitwiseBinaryOperator`, `PhanTypeMismatchBitwiseBinaryOperands`
+ Indicate when warnings about too many arguments are caused only by argument unpacking. (#1324)
  New issue types: `PhanParamTooManyUnpack`, `PhanParamTooManyUnpackInternal`
+ Properly warn about undefined namespaced constants/functions from within a namespace (#2112)
  Phan was failing to warn in some cases.
+ Always infer `int` for `<<` and `>>`
+ Support using dynamic values as the name for a `define()` statement (#2116)

Maintenance:
+ Make issue messages more consistent in their syntax used to describe closures/functions (#1695)
+ Consistently refer to instance properties as `Class->propname` and static properties as `Class::$staticpropname` in issue messages.

Bug fixes:
+ Properly type check `static::someMethodName()`.
  Previously, Phan would fail to infer types for the results of those method calls.
+ Improve handling of `array_shift`. Don't warn when it's used on a global or superglobal (#2100)
+ Infer that `self` and `static` in a trait refer to the methods of that trait. (#2006)

22 Oct 2018, Phan 1.1.1
-----------------------

New features(Analysis):
+ Add `defined at {FILE}:{LINE}` to warnings about property visibility.
+ Warn about missing references (`\n` or `$n`) in the replacement template string of `preg_replace()` (#2047)
+ Make `@suppress` on closures/functions/methods apply more consistently to issues emitted when analyzing the closure/function/method declaration. (#2071)
+ Make `@suppress` on warnings about unparseable doc comments work as expected (e.g. for `PhanInvalidCommentForDeclarationType on a class`) (#1429)
+ Support checking for missing/invalid files in `require`/`include`/`require_once`/`include_once` statements.

  To enable these checks, set `enable_include_path_checks` to `true` in your Phan config.

  New issue types: `PhanRelativePathUsed`, `PhanTypeInvalidEval`, `PhanTypeInvalidRequire`, `PhanInvalidRequireFile`, `PhanMissingRequireFile`

  New config settings: `enable_include_path_checks`, `include_paths`, `warn_about_relative_include_statement`
+ Warn when attempting to unset a property that was declared (i.e. not a dynamic or magic property) (#569)
  New issue type: `PhanTypeObjectUnsetDeclaredProperty`

  - This warning is emitted because declared properties are commonly expected to exist when they are accessed.
+ Warn about iterating over an object that's not a `Traversable` and not `stdClass` (#1115)
  New issue types (for those objects) were added for the following cases:

  1. Has no declared properties (`TypeNoPropertiesForeach`)
  2. Has properties and none are accessible. (`TypeNoAccessiblePropertiesForeach`)
  3. Has properties and some are accessible. (`TypeSuspiciousNonTraversableForeach`)
+ Add `@phan-template` and `@phan-inherits` as aliases for `@template` and `@inherits` (#2063)
+ Warn about passing non-objects to `clone()` (`PhanTypeInvalidCloneNotObject`) (#1798)

Maintenance:
+ Minor performance improvements.
+ Increase the default value of `max_literal_string_type_length` from 50 to 200.
+ Include Phan version in Phan's error handler and exception handler output. (#1639)

Bug fixes:
+ Don't crash when parsing an invalid cast expression. Only the fallback/polyfill parsers were affected.

Language Server/Daemon mode:
+ Fix bugs in the language server.

  1. The language server was previously using the non-PCNTL fallback
     implementation unconditionally due to an incorrect default configuration value.
     After this fix, the language server properly uses PCNTL by default
     if PCNTL is available.

     This bug was introduced by PR #1743

  2. Fix a bug causing the language server to eventually run out of memory when PCNTL was disabled.

08 Oct 2018, Phan 1.1.0
-----------------------

Maintenance:
+ Work on making this compatible with `php-ast` 1.0.0dev. (#2038)
  (Phan continues to support php-ast 0.1.5 and newer)

  Remove dead code (such as helper functions and references to constants) that aren't needed when using AST version 50 (which Phan uses).

  Some plugins may be affected if they call these helper methods or use those constants when the shim is used.

Bug fixes:
+ Fix a crash parsing an empty `shell_exec` shorthand string when using the fallback parser
  (i.e. two backticks in a row)
+ Fix a false positive `PhanUnusedVariable` warning about a variable declared prior to a do/while loop (#2026)

02 Oct 2018, Phan 1.0.7
-----------------------

New features(Analysis):
+ Support the `(int|string)[]` syntax of union types (union of multiple types converted to an array) in PHPDoc (#2008)

  e.g. `@param (int|string)[] $paramName`, `@return (int|string)[]`
+ Support spaces after commas in array shapes (#1966)
+ Emit warnings when using non-strings as dynamic method names (e.g. `$o->{$notAString}()`)
  New issue types: `PhanTypeInvalidMethodName`, `PhanTypeInvalidStaticMethodName`, `PhanTypeInvalidCallableMethodName`

Plugins:
+ In HasPHPDocPlugin, use a more compact representation to show what Phan sees from the raw doc comment.
+ In HasPHPDocPlugin, warn about global functions without extractable PHPDoc summaries.

  New issue types: `PhanPluginNoCommentOnFunction`, `PhanPluginDescriptionlessCommentOnFunction`
+ In HasPHPDocPlugin, warn about methods without extractable PHPDoc summaries.

  New issue types: `PhanPluginNoCommentOn*Method`, `PhanPluginDescriptionlessCommentOn*Method`

  These can be suppressed based on the method FQSEN with `plugin_config => [..., 'has_phpdoc_method_ignore_regex' => (a PCRE regex)]`
  (e.g. to suppress issues about tests, or about missing documentation about getters and setters, etc.)

Bug fixes:
+ Fix false positive `PhanUnusedVariable` for variables declared before break/continue that are used after the loop. (#1985)
+ Properly emit `PhanUnusedVariable` for variables where definitions are shadowed by definitions in branches and/or loops. (#2012)
+ Properly emit `PhanUnusedVariable` for variables which are redefined in a 'do while' loop.
+ Be more consistent about emitting `PhanUnusedVariableCaughtException` when exception variable names are reused later on.
+ Fix a crash when parsing `@method` annotations with many parameters (#2019)

25 Sep 2018, Phan 1.0.6
-----------------------

New features(Analysis):
+ Be more consistent about warning about undeclared properties in some edge cases.
  New issue types: `PhanUndeclaredClassProperty`, `PhanUndeclaredClassStaticProperty`

Maintenance:
+ Restore test files in future published releases' **git tags** (#1986)
  (But exclude them from the zip/tar archives published on GitHub Releases)

  - When `--prefer-dist` (the default) is used in composer to download a stable release,
    the test files will not be part of the downloaded files.

Language Server/Daemon mode:
+ Add support for code completion suggestions. (#1706)

  This can be enabled by passing `--language-server-enable-completion`

  This will complete references to the following element types:

  - variable names (using superglobals and local variables that have been declared in the scope)
  - global constants, global functions, and class names.
  - class constants, instance and static properties, and instance and static method names.

  NOTE: If you are completing from the empty string (e.g. immediately after `->` or `::`),
  Phan may interpret the next word token (e.g. on the next line) as the property/constant name/etc. to complete,
  due to the nature of the parser used (The cursor position doesn't affect the parsing logic).

  - Completion requests before tokens that can't be treated that way will not cause that problem.
    (such as `}`, `;`, `)`, the end of the file, etc.)

Bug fixes:
+ Fix various uncaught errors in Phan that occurred when parsing invalid ASTs.
  Instead of crashing, warn about the bug or invalid AST.

  New issue types: `PhanInvalidConstantFQSEN`, `PhanContextNotObjectUsingSelf`, `PhanInvalidTraitUse` (for unparsable trait uses)

21 Sep 2018, Phan 1.0.5
-----------------------

New Features(Analysis)
+ Warn if a PHPDoc annotation for an element(`@param`, `@method`, or `@property*`) is repeated. (#1963)

  New issue types: `PhanCommentDuplicateMagicMethod`, `PhanCommentDuplicateMagicProperty`, `PhanCommentDuplicateParam`
+ Add basic support for `extract()` (#1978)
+ Improve line numbers for warnings about `@param` and `@return` annotations (#1369)

Maintenance:
+ Make `ext-ast` a suggested composer dependency instead of a required composer dependency (#1981)

  `--use-fallback-parser` allows Phan to analyze files even when php-ast is not installed or enabled.
+ Remove test files from future published releases (#1982)

Plugins:
+ Properly warn about code after `break` and `continue` in `UnreachableCodePlugin`.
  Previously, Phan only warned about code after `throw` and `return`.

Bug fixes:
+ Don't infer bad types for variables when analyzing `array_push` using expressions containing those variables. (#1955)

  (also fixes other `array_*` functions taking references)
+ Fix false negatives in PHP5 backwards compatibility heuristic checks (#1939)
+ Fix false positive `PhanUnanalyzableInheritance` for a method inherited from a trait (which itself uses trait) (#1968)
+ Fix an uncaught `RuntimeException` when type checking an array that was roughly 12 or more levels deep (#1962)
+ Improve checks of the return type of magic methods against methods inherited from ancestor classes (#1975)

  Don't emit a false positive `PhanParamSignaturePHPDocMismatchReturnType`

Language Server/Daemon mode:
+ Fix an uncaught exception when extracting a URL with an unexpected scheme (not `file:/...`) (#1960)
+ Fix false positive `PhanUnreferencedUseNormal` issues seen when the daemon was running without pcntl (#1860)

10 Sep 2018, Phan 1.0.4
-----------------------

Plugins:
+ Fix a crash in `DuplicateExpressionPlugin`.
+ Add `HasPHPDocPlugin`, which checks if an element (class or property) has a PHPDoc comment,
  and that Phan can extract a plaintext summary/description from that comment.

Language Server/Daemon mode:
+ Support generating a hover description for variables.

  - For union types with a single non-nullable class/interface type, the hover text include the full summary description of that class-like.
  - For non-empty union types, this will just show the raw union type (e.g. `string|false`)
+ Improve extraction of summaries of elements (e.g. hover description)

  - Support using `@return` as a summary for function-likes.
  - Parse the lines after `@var` tag (before subsequent tags)
    as an additional part of the summary for constants/properties.

Maintenance:
+ Update Phar file to contain missing LICENSEs (#1950)
+ Add more documentation to Phan's code.

07 Sep 2018, Phan 1.0.3
-----------------------

Bug fixes
+ Fix bugs in analysis of assignments within conditionals (#1937)
+ Fix a crash analyzing comparison with variable assignment expression (#1940)
  (e.g. `if (1 + 1 > ($var = 1))`)

Plugins:
+ Update `SleepCheckerPlugin` to warn about properties that aren't returned in `__sleep()`
  that don't have a doc comment annotation of `@phan-transient` or `@transient`.
  (This is not an officially specified annotation)

  New issue type: `SleepCheckerPropertyMissingTransient`

  New setting: `$config['plugin_config']['sleep_transient_warning_blacklist_regex']`
  can be used to prevent Phan from warning about certain properties missing `@phan-transient`

06 Sep 2018, Phan 1.0.2
-----------------------

New features(Analysis)
+ Allow spaces on either side of `|` in union types
  (e.g. `@param array | ArrayAccess $x`)
+ Warn about array destructuring assignments from non-arrays (#1818)
  (E.g. `[$x] = 2`)

  New issue type: `PhanTypeInvalidExpressionArrayDestructuring`
+ Infer the number of groups for $matches in `preg_match()`

  Named subpatterns, non-capturing patterns, and regular expression options are not supported yet.
  Phan will just infer a more generic type such as `string[]` (depending on the bit flags).
+ Warn about ambiguous uses of `Closure():void` in phpdoc.
  Also, make that syntax count as a reference to `use Closure;` in that namespace.
+ Track the line number of magic method and magic properties (Instead of reporting the line number of the class).

Bug fixes
+ Fix a crash seen when using a temporary expression in a write context. (#1915)

  New issue type: `PhanInvalidWriteToTemporaryExpression`
+ Fix a crash seen with --use-fallback-parser with an invalid expression after `new`
+ Properly infer that closures have a class name of `Closure` for some issue types.
  (e.g. `call_user_func([function() {}, 'invalidMethod'])`)
+ Fix a bug analyzing nested assignment in conditionals (#1919)
+ Don't include impossible types when analyzing assertions such as `is_string($var)` (#1932)

26 Aug 2018, Phan 1.0.1
-----------------------

New features(CLI,Configs)
+ Support setting a `target_php_version` of PHP 7.3 in the config file or through `--target-php-version`.
+ Assume that `__construct`, `__destruct`, `__set`, `__get`, `__unset`, `__clone`, and `__wakeup` have return types of void if unspecified.

New features(Analysis)
+ Add function signatures for functions added/modified in PHP 7.3. (#1537)
+ Improve the line number for warnings about unextractable `@property*` annotations.
+ Make Phan aware that `$x` is not false inside of loops such as `while ($x = dynamic_value()) {...}` (#1646)
+ Improve inferred types of `$x` in complex equality/inequality checks such as `if (($x = dynamic_value()) !== false) {...}`
+ Make `!is_numeric` assertions remove `int` and `float` from the union type of an expression. (#1895)
+ Preserve any matching original types in scalar type assertions (#1896)
  (e.g. a variable `$x` of type `?int|?MyClass` will have type `int` after `assert(is_numeric($x))`)

Maintenance:
+ Add/modify various function, methods, and property type signatures.

Plugins:
+ Add `UnknownElementTypePlugin` to warn about functions/methods
  that have param/return types that Phan can't infer anything about.
  (it can still infer some things in non-quick mode about parameters)

  New issue types: `PhanPluginUnknownMethodReturnType`, `PhanPluginUnknownClosureReturnType`, `PhanPluginUnknownFunctionReturnType`, `PhanPluginUnknownPropertyType`
+ Add `DuplicateExpressionPlugin` to warn about duplicated expressions such as:
  - `X == X`, `X || X`, and many other binary operators (for operators where it is likely to be a bug)
  - `X ? X : Y` (can often simplify to `X ?: Y`)
  - `isset(X) ? X : Y` (can simplify to `??` in PHP 7)

  New issue types: `PhanPluginDuplicateExpressionBinaryOp`, `PhanPluginDuplicateConditionalTernaryOperation`, `PhanPluginDuplicateConditionalNullCoalescing`
+ Improve types inferred for `$matches` for PregRegexCheckerPlugin.

Bug fixes:
+ Properly handle `CompileError` (that are not the subclass `ParseError`). CompileError was added in PHP 7.3.
  (Phan now logs these the same way it would log other syntax errors, instead of treating this like an unexpected Error.)
+ Make sure that private methods that are generators, that are inherited from a trait, aren't treated like a `void`.
+ Fix a crash analyzing a dynamic call to a static method, which occurred when dead code detection or reference tracking was enabled. (#1889)
+ Don't accidentally emit false positive issues about operands of binary operators in certain contexts. (#1898)

12 Aug 2018, Phan 1.0.0
-----------------------

The Phan 1.0.0 release supports analysis of php 7.0-7.2, and can be executed with php 7.0+.
This release replaces the previous 0.12.x releases.
Because Phan uses PHP's Reflection APIs, it's recommended to use the same PHP minor version for analyzing the code as would be used to run the code.
(For the small number of function/method signatures, etc., that were added or changed in each minor release of PHP.)

Plugins:
+ Plugins: Remove V1 plugins (and V1 plugin examples), as well as legacy plugin capabilities. (#249)
  Third party plugin authors must use V2 of the plugin system.

  Removed capabilities:

  - `AnalyzeNodeCapability`, `LegacyAnalyzeNodeCapability`, `LegacyPostAnalyzeNodeCapability` (use `PostAnalyzeNodeCapability` instead)
  - `LegacyPreAnalyzeNodeCapability` (use `PreAnalyzeNodeCapability` instead)
+ API: Remove various methods that were deprecated. (#249)
  Any plugins using those methods will need to be updated.
  (e.g. `Config::getValue('config_value')` must be used instead of `Config::get()->config_value`)
+ Config: Remove `progress_bar_sample_rate` (#249)
  (`progress_bar_sample_interval` must be used instead if you want the progress bar to be faster or slower)
+ Maintenance: Immediately report the exception and exit if any plugins threw an uncaught `Throwable` during initialization.
  (E.g. this results in a better error message when a third party plugin requires PHP 7.1 syntax but PHP 7.0 is used to run Phan)

21 Jul 2018, Phan 0.12.15
-------------------------

New features(Analysis)
+ Make Phan's unused variable detection also treat exception variables as variable definitions,
  and warn if the caught exception is unused. (#1810)
  New issue types: `PhanUnusedVariableCaughtException`
+ Be more aggressive about inferring that a method has a void return type, when it is safe to do so
+ Emit `PhanInvalidConstantExpression` in some places where PHP would emit `"Constant expression contains invalid operations"`

  Phan will replace the default parameter type (or constant type) with `mixed` for constants and class constants.

  Previously, this could cause Phan to crash, especially with `--use-fallback-parser` on invalid ASTs.
+ Improve analysis of arguments passed to `implode()`

New features(CLI)
+ Add `--daemonize-tcp-host` CLI option for specifying the hostname for daemon mode (#1868).
  The default will remain `127.0.0.1` when not specified.
  It can be overridden to values such as `0.0.0.0` (publicly accessible, e.g. for usage with Docker)

Language Server/Daemon mode:
+ Implement support for hover requests in the Language Server (#1738)

  This will show a preview of the element definition (showing signature types instead of PHPDoc types)
  along with the snippet of the element description from the doc comment.

  Clients that use this should pass in the CLI option `--language-server-enable-hover` when starting the language server.

  - Note that this implementation assumes that clients sanitize the mix of markdown and HTML before rendering it.
  - Note that this may slow down some language server clients if they pause while waiting for the hover request to finish.

Maintenance:
+ Add a workaround for around a notice in PHP 7.3alpha4  (that Phan treats as fatal) (#1870)

Bug fixes:
+ Fix a bug in checking if nullable versions of specialized type were compatible with other nullable types. (#1839, #1852)
  Phan now correctly allows the following type casts:

  - `?1`               can cast to `?int`
  - `?'a string'`      can cast to `?string`
  - `?Closure(T1):T2`  can cast to `?Closure`
  - `?callable(T1):T2` can cast to `?callable`,
+ Make `exclude_file_list` work more consistently on Windows.

08 Jul 2018, Phan 0.12.14
-------------------------

New features(CLI, Configs)
+ Add `warn_about_undocumented_throw_statements` and `exception_classes_with_optional_throws_phpdoc` config. (#90)

  If `warn_about_undocumented_throw_statements` is true, Phan will warn about uncaught throw statements that aren't documented in the function's PHPDoc.
  (excluding classes listed in `exception_classes_with_optional_throws_phpdoc` and their subclasses)
  This does not yet check function and method calls within the checked function that may themselves throw.

  Add `warn_about_undocumented_exceptions_thrown_by_invoked_functions`.
  If enabled (and `warn_about_undocumented_throw_statements` is enabled),
  Phan will warn about function/closure/method invocations that have `@throws`
  that aren't caught or documented in the invoking method.
  New issue types: `PhanThrowTypeAbsent`, `PhanThrowTypeAbsentForCall`,
  `PhanThrowTypeMismatch`, `PhanThrowTypeMismatchForCall`

  Add `exception_classes_with_optional_throws_phpdoc` config.
  Phan will not warn about lack of documentation of `@throws` for any of the configured classes or their subclasses.
  The default is the empty array (Don't suppress any warnings.)
  (E.g. Phan suppresses `['RuntimeException', 'AssertionError', 'TypeError']` for self-analysis)

New Features (Analysis):
+ Warn when string literals refer to invalid class names (E.g. `$myClass::SOME_CONSTANT`). (#1794)
  New issue types: `PhanTypeExpectedObjectOrClassNameInvalidName` (emitted if the name can't be used as a class)
  This will also emit `PhanUndeclaredClass` if the class name could not be found.
+ Make Phan aware that `$this` doesn't exist in a static closure (#768)

Language Server/Daemon mode:
+ Fix another rare bug that can cause crashes in the polyfill/fallback parser when parsing invalid or incomplete ASTs.
+ Add a `--language-server-hide-category` setting to hide the issue category from diagnostic messages.
+ Remove the numeric diagnostic code from the language server diagnostics (a.k.a. issues).
  (Certain language clients such as LanguageClient-neovim would render that the code in the quickfix menu, wasting space)
+ Support "go to definition" for union types within all code comment types  (#1704)
  (e.g. can go to definition in `// some annotation or comment mentioning MyType`)

New features(Analysis)
+ Support analysis of [`list()` reference assignment](https://wiki.php.net/rfc/list_reference_assignment) for php 7.3 (which is still in alpha). (#1537)
+ Warn about invalid operands of the unary operators `+`, `-`, and `~`
  New issue types: `PhanTypeInvalidUnaryOperandNumeric` and `PhanTypeInvalidUnaryOperandBitwiseNot` (#680)

Bug fixes:
+ Fix a bug causing Phan to infer extra wrong types (`ancestorClass[][]`) for `@return className[]` (#1822)
+ Start warning about assignment operations (e.g. `+=`) when the modified variable isn't referenced later in the function.
+ Make exceptions in `catch{}` always include the type `Throwable` even if the declared type doesn't. (#336)

16 Jun 2018, Phan 0.12.13
-------------------------

New features(Analysis)
+ Support integer literals both in PHPDoc and in Phan's type system. (E.g. `@return -1|string`)
  Include integer values in issue messages if the values are known.
+ Support string literals both in PHPDoc and in Phan's type system. (E.g. `@return 'example\n'`)
  Phan can now infer possible variable values for dynamic function/method calls, etc.

  Note: By default, Phan does not store representations of strings longer than 50 characters. This can be increased with the `'max_literal_string_type_length'` config.

  Supported escape codes: `\\`, `\'`, `\r`, `\n`, `\t`, and hexadecimal (`\xXX`).
+ Improve inferred types of unary operators.
+ Warn about using `void`/`iterable`/`object` in use statements based on `target_php_version`. (#449)
  New issue types: `PhanCompatibleUseVoidPHP70`, `PhanCompatibleUseObjectPHP71`, `PhanCompatibleUseObjectPHP71`
+ Warn about making overrides of inherited property and constants less visible (#788)
  New issue types: `PhanPropertyAccessSignatureMismatch`, `PhanPropertyAccessSignatureMismatchInternal`,
  `PhanConstantAccessSignatureMismatch`, `PhanConstantAccessSignatureMismatchInternal`.
+ Warn about making static properties into non-static properties (and vice-versa) (#615)
  New issue types: `PhanAccessNonStaticToStaticProperty`, `PhanAccessStaticToNonStaticProperty`
+ Warn about inheriting from a class/trait/interface that has multiple possible definitions (#773)
  New issue types: `PhanRedefinedExtendedClass`, `PhanRedefinedUsedTrait`, `PhanRedefinedInheritedInterface`
+ Infer more accurate types for the side effects of assignment operators (i.e. `+=`, `.=`, etc) and other binary operations. (#1775)
+ Warn about invalid arguments to binary operators or assignment operators.
  New issue types: `PhanTypeInvalidLeftOperandOfAdd`,  `PhanTypeInvalidLeftOperandOfNumericOp`,
                   `PhanTypeInvalidRightOperandOfAdd`, `PhanTypeInvalidRightOperandOfNumericOp`
+ Warn about using negative string offsets and multiple catch exceptions in PHP 7.0 (if `target_php_version` is less than `'7.1'`). (#1771, #1778)
  New issue types: `PhanCompatibleMultiExceptionCatchPHP70`, `PhanCompatibleNegativeStringOffset`.

Maintenance:
+ Update signature map with more accurate signatures (#1761)
+ Upgrade tolerant-php-parser, making the polyfill/fallback able to parse PHP 7.1's multi exception catch.

Bug fixes:
+ Don't add more generic types to properties with more specific PHPDoc types (#1783).
  For example, don't add `array` to a property declared with PHPDoc type `/** @var string[] */`
+ Fix uncaught `AssertionError` when `parent` is used in PHPDoc (#1758)
+ Fix various bugs that can cause crashes in the polyfill/fallback parser when parsing invalid or incomplete ASTs.
+ Fix unparsable/invalid function signature entries of rarely used functions
+ Warn about undefined variables on the left-hand side of assignment operations (e.g. `$x .= 'string'`) (#1613)

08 Jun 2018, Phan 0.12.12
-------------------------

Maintenance:
+ Increase the severity of some issues to critical
  (if they are likely to cause runtime Errors in the latest PHP version).

Bug fixes:
+ Allow suppressing `PhanTypeInvalidThrows*` with doc comment suppressions
  in the phpdoc of a function/method/closure.
+ Fix crashes when fork pool is used and some issue types are emitted (#1754)
+ Catch uncaught exception for PhanContextNotObject when calling `instanceof self` outside a class scope (#1754)

30 May 2018, Phan 0.12.11
-------------------------

Language Server/Daemon mode:
+ Make the language server work more reliably when `pcntl` is unavailable. (E.g. on Windows) (#1739)
+ By default, allow the language server and daemon mode to start with the fallback even if `pcntl` is unavailable.
  (`--language-server-require-pcntl` can be used to make the language server refuse to start without `pcntl`)

Bug fixes:
+ Don't crash if `ext-tokenizer` isn't installed (#1747)
+ Fix invalid output of `tool/make_stubs` for APCu (#1745)

27 May 2018, Phan 0.12.10
-------------------------

New features(CLI, Configs)
+ Add CLI flag `--unused-variable-detection`.
+ Add config setting `unused_variable_detection` (disabled by default).
  Unused variable detection can be enabled by `--unused-variable-detection`, `--dead-code-detection`, or the config.

New features(Analysis):
+ Add built-in support for unused variable detection. (#345)
  Currently, this is limited to analyzing inside of functions, methods, and closures.
  This has some minor false positives with loops and conditional branches.

  Warnings about unused parameters can be suppressed by adding `@phan-unused-param` on the same line as `@param`,
  e.g. `@param MyClass $x @phan-unused-param`.
  (as well as via standard issue suppression methods.)

  The built in unused variable detection support will currently not warn about any of the following issue types, to reduce false positives.

  - Variables beginning with `$unused` or `$raii` (case-insensitive)
  - `$_` (the exact variable name)
  - Superglobals, used globals (`global $myGlobal;`), and static variables within function scopes.
  - Any references, globals, or static variables in a function scope.

  New Issue types:
  - `PhanUnusedVariable`,
  - `PhanUnusedVariableValueOfForeachWithKey`, (has a high false positive rate)
  - `PhanUnusedPublicMethodParameter`, `PhanUnusedPublicFinalMethodParameter`,
  - `PhanUnusedProtectedMethodParameter`, `PhanUnusedProtectedFinalMethodParameter`,
  - `PhanUnusedPrivateMethodParameter`, `PhanUnusedProtectedFinalMethodParameter`,
  - `PhanUnusedClosureUseVariable`, `PhanUnusedClosureParameter`,
  - `PhanUnusedGlobalFunctionParameter`

  This is similar to the third party plugin `PhanUnusedVariable`.
  The built-in support has the following changes:

  - Emits fewer/different false positives (e.g. when analyzing loops), but also detects fewer potential issues.
  - Reimplemented using visitors extensively (Similar to the code for `BlockAnalysisVisitor`)
  - Uses a different data structure from `PhanUnusedVariable`.
    This represents all definitions of a variable, instead of just the most recent one.
    This approximately tracks the full graph of definitions and uses of variables within a function body.
    (This allows warning about all unused definitions, or about definitions that are hidden by subsequent definitions)
  - Integration: This is planned to be integrated with other features of Phan, e.g. "Go to Definition" for variables. (Planned for #1211 and #1705)

Bug fixes:
+ Minor improvements to `UnusedSuppressionPlugin`

Misc:
+ Support  `composer.json`'s `vendor-dir` for `phan --init`

22 May 2018, Phan 0.12.9
------------------------

New features(CLI, Configs):
+ Add CLI flag `--language-server-enable-go-to-definition`. See the section "Language Server/Daemon mode".
+ Add Config setting `disable_line_based_suppression` to disable line-based suppression from internal comments. See the section "New Features"
+ Add Config setting `disable_file_based_suppression` to disable file-based issue suppressions.

New features(Analysis):
+ Make `@suppress`, `@phan-suppress`, `@phan-file-suppress` accept a comma separated issue list of issue types to suppress. (#1715)
  Spaces aren't allowed before the commas.
+ Implement `@phan-suppress-current-line` and `@phan-suppress-next-line` to suppress issues on the current or next line.

  These can occur within any comment or doc comment (i.e. the comment types for `/*`, `//`, and `/**`)

  These suppressions accept a comma separated list of issue type names.
  Commas must be immediately after the previous issue type.

  Note: Phan currently does not support inline comments anywhere else.
  Phan also does not associate these inline comments with any information about the current scope.
  This suppression is based on tokenizing the PHP file and determining the line based on that comment line.

  Examples:

  ```php
  // @phan-suppress-next-line PhanUndeclaredVariable, PhanUndeclaredFunction optional reason goes here
  $result = call_undefined_function() + $undefined_variable;

  $closure();  /* @phan-suppress-current-line PhanParamTooFew optional reason for suppression */

  /**
   * This can also be used within doc comments:

   * @phan-suppress-next-line PhanInvalidCommentForDeclarationType optional reason for suppression
   * @property int $x
   */
  function my_example() {
  }
  ```

  `PhanUnusedSuppressionPlugin` is capable of detecting if line-based suppressions are unused.
+ Allow using `@phan-file-suppress` as a regular comment anywhere within a file (`//`, `/*`, or `/**` comments).
  Previously, `@phan-file-suppress` could only be used inside the doc comment of an element.

  `@phan-file-suppress` in no-op string literals will be deprecated in a future Phan release.
+ Emit class name suggestions for undeclared types in param, property, return type, and thrown type declarations. (#1689)

  Affects `PhanUndeclaredTypeParameter`, `PhanUndeclaredTypeProperty`, `PhanUndeclaredTypeReturnType`,
  `PhanUndeclaredTypeThrowsType`, and `PhanInvalidThrowsIs*`
+ Add `pretend_newer_core_methods_exist` config setting.
  If this is set to true (the default),
  and `target_php_version` is newer than the version used to run Phan,
  Phan will act as though functions added in newer PHP versions exist.

  Note: Currently only affects `Closure::fromCallable()`, which was added in PHP 7.1.
  This setting will affect more functions and methods in the future.

Language Server/Daemon mode:
+ Support "Go to definition" for properties, classes, global/class constants, and methods/global functions (Issue #1483)
  (Must pass the CLI option `--language-server-enable-go-to-definition` when starting the server to enable this)
+ Support "Go to type definition" for variables, properties, classes, and methods/global functions (Issue #1702)
  (Must pass the CLI option `--language-server-enable-go-to-definition` when starting the server to enable this)
  Note that constants can't have object types in PHP, so there's no implementation of "Go To Type Definition" for those.

Plugins:
+ Add a new plugin capability `SuppressionCapability`
  that allows users to suppress issues in additional ways. (#1070)
+ Add a new plugin `SleepCheckerPlugin`. (PR #1696)
  Warn about returning non-arrays in sleep,
  as well as about returning array values with invalid property names.

  Issue types: `SleepCheckerInvalidReturnStatement`, `SleepCheckerInvalidPropNameType`, `SleepCheckerInvalidPropName`,
  `SleepCheckerMagicPropName`, and `SleepCheckerDynamicPropName`
+ Make `PhanPregRegexCheckerPlugin` warn about the `/e` modifier on regexes (#1692)

Misc:
+ Add simple integration test for the language server mode.

Bug fixes:
+ Be more consistent about emitting `PhanUndeclaredType*` for invalid types within array shapes.
+ Avoid a crash when the left-hand side of an assignment is invalid. (#1693)
+ Prevent an uncaught `TypeError` when integer variable names (e.g. `${42}`) are used in branches (Issue #1699)

12 May 2018, Phan 0.12.8
------------------------

Bug fixes
+ Fix a crash that occurs when the `iterable<[KeyType,]ValueType>` annotation is used in phpdoc. (#1685)

08 May 2018, Phan 0.12.7
------------------------

New features:
+ For `PhanUndeclaredMethod` and `PhanUndeclaredStaticMethod` issues, suggest visible methods (in the same class) with similar names.
+ For `PhanUndeclaredConstant` issues (for class constants), suggest visible constants (in the same class) with similar names.
+ For `PhanUndeclaredProperty` and `PhanUndeclaredStaticProperty` issues, suggest visible properties (in the same class) with similar names.
+ When suggesting alternatives to undeclared classes,
  also include suggestions for similar class names within the same namespace as the undeclared class.
  (Comparing Levenshtein distance)

Language Server/Daemon mode
+ Make the latest version of `phan_client` include any suggestion alongside the issue message (for daemon mode).
+ Include text from suggestions in Language Server Protocol output

Bug fixes
+ Fix a bug generating variable suggestions when there were multiple similar variable names
  (The suggestions that would show up might not be the best set of suggestions)
+ Fix a crash in the tolerant-php-parser polyfill seen when typing out an echo statement
+ Fix incorrect suggestions to use properties (of the same name) instead of undeclared variables in class scopes.
  (Refer to static properties as `self::$name` and don't suggest inaccessible inherited private properties)
+ Don't suggest obviously invalid alternatives to undeclared classes.
  (E.g. don't suggest traits or interfaces for `new MisspelledClass`, don't suggest interfaces for static method invocations)

06 May 2018, Phan 0.12.6
------------------------

New features(Analysis)
+ Warn about properties that are read but not written to when dead code detection is enabled
  (Similar to existing warnings about properties that are written to but never read)
  New issue types: `PhanReadOnlyPrivateProperty`, `PhanReadOnlyProtectedProperty`, `PhanReadOnlyPublicProperty`
+ When warning about undeclared classes, mention any classes that have the same name (but a different namespace) as suggestions.

  E.g. `test.php:26 PhanUndeclaredClassInstanceof Checking instanceof against undeclared class \MyNS\InvalidArgumentException (Did you mean class \InvalidArgumentException)`
+ When warning about undeclared variables (outside of the global scope), mention any variables that have similar names (based on case-insensitive Levenshtein distance) as suggestions.

  In method scopes: If `$myName` is undeclared, but `$this->myName` is declared (or inherited), `$this->myName` will be one of the suggestions.
+ Warn about string and numeric literals that are no-ops. (E.g. `<?php 'notEchoedStr'; "notEchoed $x"; ?>`)
  New issue types: `PhanNoopStringLiteral`, `PhanNoopEncapsulatedStringLiteral`, `PhanNoopNumericLiteral`.

  Note: This will not warn about Phan's [inline type checks via string literals](https://github.com/phan/phan/wiki/Annotating-Your-Source-Code#inline-type-checks-via-string-literals)
+ When returning an array literal (with known keys) directly,
  make Phan infer the array literal's array shape type instead of a combination of generic array types.
+ Make type casting rules stricter when checking if an array shape can cast to a given generic array type.
  (E.g. `array{a:string,b:int}` can no longer cast to `array<string,int>`, but can cast to `array<string,int>|array<string,string>`).

  E.g. Phan will now warn about `/** @return array<string,int> */ function example() { $result = ['a' => 'x', 'b' => 2]; return $result; }`
+ Warn about invalid expressions/variables encapsulated within double-quoted strings or within heredoc strings.
  New issue type: `TypeSuspiciousStringExpression` (May also emit `TypeConversionFromArray`)

+ Add support for template params in iterable types in phpdoc. (#824)
  Phan supports `iterable<TValue>` and `iterable<TKey, TValue>` syntaxes. (Where TKey and TValue are union types)
  Phan will check that generic arrays and array shapes can cast to iterable template types.
+ Add support for template syntax of Generator types in phpdoc. (#824)
  Supported syntaxes are:

  1. `\Generator<TValue>`
  2. `\Generator<TKey,TValue>`
  3. `\Generator<TKey,TValue,TSend>` (TSend is the expected type of `$x` in `$x = yield;`)
  4. `\Generator<TKey,TValue,TSend,TReturn>` (TReturn is the expected type of `expr` in `return expr`)

  New issue types: `PhanTypeMismatchGeneratorYieldValue`, `PhanTypeMismatchGeneratorYieldKey` (For comparing yield statements against the declared `TValue` and `TKey`)

  Additionally, Phan will use `@return Generator|TValue[]` to analyze the yield statements
  within a function/method body the same way as it would analyze `@return Generator<TValue>`.
  (Analysis outside the method would not change)

+ Add support for template params in Iterator and Traversable types in phpdoc. (#824)
  NOTE: Internal subtypes of those classes (e.g. ArrayObject) are not supported yet.
  Supported syntaxes are:

  1. `Traversable<TValue>`/`Iterator<TValue>`
  2. `Traversable<TKey,TValue>`/`Iterator<TKey,TValue>`

+ Analyze `yield from` statements.

  New issue types: `PhanTypeInvalidYieldFrom` (Emitted when the expression passed to `yield from` is not a Traversable or an array)

  Warnings about the inferred keys/values of `yield from` being invalid reuse `PhanTypeMismatchGeneratorYieldValue` and `PhanTypeMismatchGeneratorYieldKey`
+ Make the union types within the phpdoc template syntax of `iterator`/`Traversable`/`Iterator`/`Generator` affect analysis of the keys/values of `foreach` statements
+ Improve Phan's analysis of array functions modifying arguments by reference, reducing false positives. (#1662)
  Affects `array_shift`/`array_unshift`/`array_push`/`array_pop`/`array_splice`.

Misc
+ Infer that a falsey array is the empty array shape.

Bug Fixes
+ Consistently warn about unreferenced declared properties (i.e. properties that are not magic or dynamically added).
  Previously, Phan would just never warn if the class had a `__get()` method (as a heuristic).

03 Apr 2018, Phan 0.12.5
------------------------

Plugins
+ Add an option `'php_native_syntax_check_max_processes'` to `'plugin_config'` for `InvokePHPNativeSyntaxCheckPlugin`.

Bug Fixes
+ Remove extra whitespace from messages of comment text in `UnextractableAnnotationElementName` (e.g. `"\r"`)
+ Fix bugs in `InvokePHPNativeSyntaxCheckPlugin`

31 Mar 2018, Phan 0.12.4
------------------------

New Features(CLI, Configs)
+ Add a `strict_param_checking` config setting. (And a `--strict-param-checking` CLI flag)
  If this is set to true, then Phan will warn if at least one of the types
  in an argument's union type can't cast to the expected parameter type.
  New issue types: `PhanPartialTypeMismatchArgument`, `PhanPossiblyNullTypeArgument`, and `PhanPossiblyFalseTypeArgument`
  (along with equivalents for internal functions and methods)

  Setting this to true will likely introduce large numbers of warnings.
  Those issue types would need to be suppressed entirely,
  or with `@phan-file-suppress`, or with `@suppress`.
+ Add a `strict_property_checking` config setting. (And a `--strict-property-checking` CLI flag)
  If this is set to true, then Phan will warn if at least one of the types
  in an assignment's union type can't cast to the expected property type.
  New issue types: `PhanPartialTypeMismatchProperty`, `PhanPossiblyNullTypeProperty`, and `PhanPossiblyFalseTypeProperty`

  NOTE: This option does not make Phan check if all possible expressions have a given property, but may do that in the future.
+ Add a `strict_return_checking` config setting. (And a `--strict-return-checking` CLI flag)
  If this is set to true, then Phan will warn if at least one of the types
  in a return statement's union type can't cast to the expected return type.
  New issue types: `PhanPartialTypeMismatchReturn`, `PhanPossiblyNullTypeReturn`, and `PhanPossiblyFalseTypeReturn`

  Setting this to true will likely introduce large numbers of warnings.
  Those issue types would need to be suppressed entirely,
  or with `@phan-file-suppress`, or with `@suppress`.
+ Add a `--strict-type-checking` CLI flag, to enable all of the new strict property/param/return type checks.
+ Add a `guess_unknown_parameter_type_using_default` config,
  which can be enabled to make Phan more aggressively infer the types of undocumented optional parameters
  from the parameter's default value.
  E.g. `function($x = 'val')` would make Phan infer that the function expects $x to have a type of `string`, not `string|mixed`.

Plugins
+ Add a new plugin `InvokePHPNativeSyntaxCheckPlugin` on all analyzed files (but not files excluded from analysis) (#629)
+ Add a new plugin capability `AfterAnalyzeFileCapability` that runs after a given file is analyzed.
  This does not get invoked for files that are excluded from analysis, or for empty files.

New Features(Analysis)
+ Detect unreachable catch statements (#112)
  (Check if an earlier catch statement caught an ancestor of a given catch statement)
+ Support phpdoc3's `scalar` type in phpdoc. (#1589)
  That type is equivalent to `bool|float|int|string`.
+ Improve analysis of return statements with ternary conditionals (e.g. `return $a ?: $b`).
+ Start analyzing negated `instanceof` conditionals such as `assert(!($x instanceof MyClass))`.
+ Infer that the reference parameter's resulting type for `preg_match` is a `string[]`, not `array` (when possible)
  (And that the type is `array{0:string,1:int}[]` when `PREG_OFFSET_CAPTURE` is passed as a flag)
+ Warn in more places when Phan can't extract union types or element identifiers from a doc comment.
  New issue types: `UnextractableAnnotationElementName`, `UnextractableAnnotationSuffix`.
  (E.g. warn about `@param int description` (ideally has param name) and `@return int?` (Phan doesn't parse the `?`, should be `@return ?int`))

Bug Fixes
+ Don't emit false positive `PhanTypeArraySuspiciousNullable`, etc. for complex isset/empty/unset expressions. (#642)
+ Analyze conditionals wrapped by `@(cond)` (e.g. `if (@array_key_exists('key', $array)) {...}`) (#1591)
+ Appending an unknown type to an array shape should update Phan's inferred keys(int) and values(mixed) of an array. (#1560)
+ Make line numbers for arguments more accurate
+ Infer that the result of `|` or `&` on two strings is a string.
+ Fix a crash caused by empty FQSENs for classlike names or function names (#1616)

24 Mar 2018, Phan 0.12.3
------------------------

New Features(CLI, Configs)
+ Add `--polyfill-parse-all-element-doc-comments` for PHP 7.0.
  If you're using the polyfill (e.g. using `--force-polyfill-parser`), this will parse doc comments on class constants in php 7.0.
  (Normally, the polyfill wouldn't include that information, to closely imitate `php-ast`'s behavior)

New Features(Analysis)
+ Infer the type of `[]` as `array{}` (the empty array), not `array`. (#1382)
+ Allow phpdoc `@param` array shapes to contain optional fields. (E.g. `array{requiredKey:int,optionalKey?:string}`) (#1382)
  An array shape is now allowed to cast to another array shape, as long as the required fields are compatible with the target type,
  and any optional fields from the target type are absent in the source type or compatible.
+ In issue messages, represent closures by their signatures instead of as `\closure_{hexdigits}`
+ Emit `PhanTypeArrayUnsetSuspicious` when trying to unset the offset of something that isn't an array or array-like.
+ Add limited support for analyzing `unset` on variables and the first dimension of arrays.
  Unsetting variables does not yet work in conditional branches.
+ Don't emit `PhanTypeInvalidDimOffset` in `isset`/`empty`/`unset`
+ Improve Phan's analysis of loose equality (#1101)
+ Add new issue types `PhanWriteOnlyPublicProperty`, `PhanWriteOnlyProtectedProperty`, and `PhanWriteOnlyPrivateProperty`,
  which will be emitted on properties that are written to but never read from.
  (Requires that dead code detection be enabled)
+ Improve Phan's analysis of switch statements and fix bugs. (#1561)
+ Add `PhanTypeSuspiciousEcho` to warn about suspicious types being passed to echo/print statements.
  This now warns about booleans, arrays, resources, null, non-stringable classes, combinations of those types, etc.
  (`var_export` or JSON encoding usually makes more sense for a boolean/null)
+ Make Phan infer that top-level array keys for expressions such as `if (isset($x['keyName']))` exist and are non-null. (#1514)
+ Make Phan infer that top-level array keys for expressions such as `if (array_key_exists('keyName', $x))` exist. (#1514)
+ Make Phan aware of types after negated of `isset`/`array_key_exists` checks for array shapes (E.g. `if (!array_key_exists('keyName', $x)) { var_export($x['keyName']); }`)
  Note: This will likely fail to warn if the variable is already a mix of generic arrays and array shapes.
+ Make Phan check that types in `@throws` annotations are valid; don't warn about classes in `@throws` being unreferenced. (#1555)
  New issue types: `PhanUndeclaredTypeThrowsType`, `PhanTypeInvalidThrowsNonObject`, `PhanTypeInvalidThrowsNonThrowable`, `PhanTypeInvalidThrowsIsTrait`, `PhanTypeInvalidThrowsIsInterface`

New types:
+ Add `Closure` and `callable` with annotated param types and return to Phan's type system(#1578, #1581).
  This is not a part of the phpdoc2 standard or any other standard.
  These can be used in any phpdoc tags that Phan is aware of,
  to indicate their expected types (`@param`, `@var`, `@return`, etc.)

  Examples:

  - `function(int $x) : ?int {return $x;}` has the type `Closure(int):?int`,
    which can cast to `callable(int):?int`
  - `function(array &$x) {$x[] = 2;}` has the type `Closure(array&):void`
  - `function(int $i = 2, int ...$args) : void {}`
    has the type `Closure(int=,int...):void`

  Note: Complex return types such as `int[]` or `int|false`
  **must** be surrounded by brackets to avoid potential ambiguities.

  - e.g. `Closure(int|array): (int[])`
  - e.g. `Closure(): (int|false)`
  - e.g. `Closure(): (array{key:string})` is not ambiguous,
    but the return type must be surrounded by brackets for now

  Other notes:
  - For now, the inner parameter list of `Closure(...)`
    cannot contain the characters `(` or `)`
    (or `,`, except to separate the arguments)
    Future changes are planned to allow those characters.
  - Phan treats `Closure(T)` as an alias of `Closure(T):void`
  - Placeholder variable names can be part of these types,
    similarly to `@method` (`Closure($unknown,int $count=0):T`
    is equivalent to `Closure(mixed,int):T`

Maintenance
+ Add `--disable-usage-on-error` option to `phan_client` (#1540)
+ Print directory which phan daemon is going to await analysis requests for (#1544)
+ Upgrade the dependency `Microsoft/tolerant-php-parser` to 0.0.10 (includes minor bug fixes)

Bug Fixes
+ Allow phpdoc `@param` array shapes to contain union types (#1382)
+ Remove leading `./` from Phan's relative paths for files (#1548, #1538)
+ Reduce false positives in dead code detection for constants/properties/methods.
+ Don't warn when base classes access protected properties of their subclasses.

02 Mar 2018, Phan 0.12.2
------------------------

New Features(Analysis)

+ Emit `PhanTypeInvalidDimOffsetArrayDestructuring` when an unknown offset value is used in an array destructuring assignment (#1534, #1477)
  (E.g. `foreach ($expr as ['key' => $value])`, `list($k) = [2]`, etc.)

Plugins
+ Add a new plugin capability `PostAnalyzeNodeCapability` (preferred) and `LegacyPostAnalyzeNodeCapability`.
  These capabilities give plugins for post-order analysis access to a list of parent nodes,
  instead of just the last parent node.
  Plugin authors should use these instead of `AnalyzeNodeCapability` and `LegacyAnalyzeNodeCapability`.

  (`parent_node_list` is set as an instance property on the visitor returned by PostAnalyzeNodeCapability
  if the instance property was declared)

Maintenance:
+ Speed up analysis when quick mode isn't used.

Bug Fixes
+ Reduce false positives in `PhanTypeInvalidDimOffset`
+ Don't warn when adding new keys to an array when assigning multiple dimensions at once (#1518)
+ Reduce false positives when a property's type gets inferred as an array shape(#1520)
+ Reduce false positives when adding fields to an array in the global scope.
+ Reduce false positives by converting array shapes to generic arrays before recursively analyzing method/function invocations (#1525)

28 Feb 2018, Phan 0.12.1
------------------------

New Features(Analysis)
+ Emit `PhanTypeInvalidDimOffset` when an unknown offset is fetched from an array shape type. (#1478)

Bug Fixes
+ Fix an "Undefined variable" error when checking for php 7.1/7.0 incompatibilities in return types. (#1511)
  Fix other crashes.

25 Feb 2018, Phan 0.12.0
------------------------

The Phan 0.12.0 release supports analysis of php 7.0-7.2, and can be executed with php 7.0+.
This release replaces the previous releases (The 0.11 releases for php 7.2, the 0.10 releases for php 7.1, and the 0.8 releases for php 7.0)
Because Phan uses Reflection, it's recommended to use the same PHP minor version for analyzing the code as would be used to run the code.
(For the small number of function/method signatures, etc., that were added or changed in each minor release of PHP.)

After upgrading Phan, projects using phan should add a `target_php_version` setting to their `.phan/config.php`.

New Features(CLI, Configs)
+ Add a `target_php_version` config setting, which can be set to `'7.0'`, `'7.1'`, `'7.2'`, or `null`/`'native'`. (#1174)
  This defaults to the same PHP minor version as the PHP binary used to run Phan.
  `target_php_version` can be overridden via the CLI option `--target-php-version {7.0,7.1,7.2,native}`

  NOTE: This setting does not let a PHP 7.0 installation parse PHP 7.1 nullable syntax or PHP 7.1 array destructuring syntax.

  If you are unable to upgrade the PHP version used for analysis to php 7.1, the polyfill parser settings may help
  (See `--force-polyfill-parser` or `--use-fallback-parser`. Those have a few known bugs in edge cases.)
+ Add `--init` CLI flag and CLI options to affect the generated config. (#145)
  (Options: `--init-level=1..5`, `--init-analyze-dir=path/to/src`, `--init-analyze-file=path/to/file.php`, `--init-no-composer`, `--init-overwrite`)

New Features(Analysis)
+ In doc comments, support `@phan-var`, `@phan-param`, `@phan-return`, `@phan-property`, and `@phan-method`. (#1470)
  These annotations will take precedence over `@var`, `@param`, `@return`, `@property`, and `@method`.
+ Support `@phan-suppress` as an alias of `@suppress`.
+ Add a non-standard way to explicitly set var types inline.  (#890)
  `; '@phan-var T $varName'; expression_using($varName);` and
  `; '@phan-var-force T $varName'; expression_using($varName);`

  If Phan sees a string literal containing `@phan-var` as a top-level statement of a statement list, it will immediately set the type of `$varName` to `T` without any type checks.
  (`@phan-var-force T $x` will do the same thing, and will create the variable if it didn't already exist).

  Note: Due to limitations of the `php-ast` parser, Phan isn't able to use inline doc comments, so this is the solution that was used instead.

  Example Usage:

  ```php
  $values = mixed_expression();

  // Note: This annotation must go **after** setting the variable.
  // This has to be a string literal; phan cannot parse inline doc comments.
  '@phan-var array<int,MyClass> $values';

  foreach ($x as $instance) {
      function_expecting_myclass($x);
  }
  ```
+ Add a way to suppress issues for the entire file (including within methods, etc.) (#1190)
  The `@phan-file-suppress` annotation can also be added to phpdoc for classes, etc.
  This feature is recommended for use at the top of the file or on the first class in the file.
  It may or may not affect statements above the suppression.
  This feature may fail to catch certain issues emitted during the parse phase.

  ```php
  <?php
  // Add a suppression for remaining statements in this file.
  '@phan-file-suppress PhanUnreferencedUseNormal (description)';
  use MyNS\MyClass;
  // ...

  /** @SomeUnreadableAnnotation {MyClass} */
  class Example { }
  ```

+ Add `CompatibleNullableTypePHP70`, `CompatibleShortArrayAssignPHP70`, `CompatibleKeyedArrayAssignPHP70`,
  `CompatibleKeyedArrayAssignPHP70`, and `CompatibleIterableTypePHP70`, (#1174, #624, #449)
  which are emitted when the `target_php_version` is less than '7.1'.
+ Add `CompatibleObjectTypePHP71`, which is emitted for the `object` typehint when the `target_php_version`
  is less than 7.2. (#1174, #827)
+ Add `PhanTypeMismatchDimFetchNullable`, which is emitted if the non-null
  version of the dimension type would be a valid index. (#1472)
+ Emit `PhanTypeArraySuspiciousNullable` when accessing fields of a nullable array (now including `?(T[])`, etc.). (#1472)
  (Stop emitting PhanTypeArraySuspicious for `?array`)
+ Add `PhanNoopBinaryOperator` and `PhanNoopUnaryOperator` checks (#1404)
+ Add `PhanCommentParamOutOfOrder` code style check. (#1401)
  This checks that `@param` annotations appear in the same order as the real parameters.
+ Detect unused imports (Does not parse inline doc comments) (#1095)
  Added `PhanUnreferencedUseNormal`, `PhanUnreferencedUseFunction`, `PhanUnreferencedUseConstant`.

  (Note that Phan does not parse inline doc comments, which may cause false positives for `PhanUnreferencedUseNormal`)
+ Add `PhanTypeMismatchArrayDestructuringKey` checks for invalid array key types in list assignments (E.g. `list($x) = ['key' => 'value']` (#1383)

Language Server
+ Support running Language Server and daemon mode on Windows (#819)
  (the `pcntl` dependency is no longer mandatory for running Phan as a server)
  The `--language-server-allow-missing-pcntl` option must be set by the client.

  When this fallback is used, Phan **manually** saves and restores the
  data structures that store information about the project being analyzed.

  This fallback is new and experimental.
+ Make Phan Language Server analyze new files added to a project (Issue #920)
+ Analyze all of the PHP files that are currently opened in the IDE
  according to the language server client,
  instead of just the most recently edited file (Issue #1147)
  (E.g. analyze other files open in tabs or split windows)
+ When closing or deleting a file, clear the issues that were emitted
  for that file.
+ If analysis requests (opening files, editing files, etc)
  are arriving faster than Phan can analyze and generate responses,
  then buffer the file changes (until end of input)
  and then begin to generate analysis results.

  Hopefully, this should reduce the necessity for limiting Phan to
  analyzing only on save.

Bug fixes
+ In files with multiple namespaces, don't use `use` statements from earlier namespaces. (#1096)
+ Fix bugs analyzing code using functions/constants provided by group use statements, in addition to `use function` and `use const` statements.

14 Feb 2018, Phan 0.11.3
------------------------

### Ported from Phan 0.10.5

New Features(CLI, Configs)
+ Add `--allow-polyfill-parser` and `--force-polyfill-parser` options.
  These allow Phan to be run without installing `php-ast`.

  Using the native `php-ast` extension is still recommended.
  The polyfill is slower and has several known bugs.

  Additionally, the way doc comments are parsed by the polyfill is different.
  Doc comments for elements such as closures may be parsed differently from `php-ast`

Maintenance:
+ Fix bugs in the `--use-fallback-parser` mode.
  Upgrade the `tolerant-php-parser` dependency (contains bug fixes and performance improvements)

Bug fixes
+ Fix a bug in `tool/make_stubs` when generating stubs of namespaced global functions.
+ Fix a refactoring bug that caused methods and properties to fail to be inherited (#1456)
+ If `ignore_undeclared_variables_in_global_scope` is true, then analyze `assert()`
  and conditionals in the global scope as if the variable was defined after the check.

11 Feb 2018, Phan 0.11.2
------------------------

### Ported from Phan 0.10.4

New Features(Analysis)

+ Support array key types of `int`, `string`, and `mixed` (i.e. `int|string`) in union types such as `array<int,T>` (#824)

  Check that the array key types match when assigning expected param types, return types, property types, etc.
  By default, an array with a key type of `int` can't cast to an array key type of `string`, or vice versa.
  Mixed union types in keys can cast to/from any key type.

  - To allow casting `array<int,T>` to `array<string,T>`, enable `scalar_array_key_cast` in your `.phan/config.php`.

+ Warn when using the wrong type of array keys offsets to fetch from an array (E.g. `string` key for `array<int,T>`) (Issue #1390)
+ Infer array key types of `int`, `string`, or `int|string` in `foreach` over arrays. (#1300)
  (Phan's type system doesn't support inferring key types for `iterable` or `Traversable` right now)
+ Support **parsing** PHPDoc array shapes
  (E.g. a function expecting `['field' => 'a string']` can document this as `@param array{field:string}` $options)
  For now, this is converted to generic arrays (Equivalent to `string[]`).

  `[[0, ...], new stdClass]` would have type `array{0:int[], 1:string}`

  - The field value types can be any union type.
  - Field keys are currently limited to keys matching the regex `[-_.a-zA-Z0-9\x7f-\xff]+`. (Identifiers, numbers, '-', and '.')
    Escape mechanisms such as backslashes (e.g. "\x20" for " ") may be supported in the future.
+ Add `PhanTypeMismatchUnpackKey` and `PhanTypeMismatchUnpackValue` to analyze array unpacking operator (also known as splat) (#1384)

  Emit `PhanTypeMismatchUnpackKey` when passing iterables/arrays with invalid keys to the unpacking operator (i.e. `...`).

  Emit `PhanTypeMismatchUnpackValue` when passing values that aren't iterables or arrays to the unpacking operator.
  (See https://secure.php.net/manual/en/migration56.new-features.php#migration56.new-features.splat)
+ When determining the union type of an array literal,
  base it on the union types of **all** of the values (and all of the keys) instead of just the first 5 array elements.
+ When determining the union type of the possible value types of an array literal,
  combine the generic types into a union type instead of simplifying the types to `array`.
  In practical terms, this means that `[1,2,'a']` is seen as `array<int,int|string>`,
  which Phan represents as `array<int,int>|array<int,string>`.

  In the previous Phan release, the union type of `[1,2,'a']` would be represented as `int[]|string[]`,
  which is equivalent to `array<mixed,int>|array<mixed,string>`

  Another example: `[$strKey => new MyClass(), $strKey2 => $unknown]` will be represented as
  `array<string,MyClass>|array<string,mixed>`.
  (If Phan can't infer the union type of a key or value, `mixed` gets added to that key or value.)
+ Improve analysis of try/catch/finally blocks (#1408)
  Analyze `catch` blocks with the inferences about the `try` block.
  Analyze a `finally` block with the combined inferences from the `try` and `catch` blocks.
+ Account for side effects of `&&` and `||` operators in expressions, outside of `if`/`assert` statements. (#1415)
  E.g. `$isValid = ($x instanceof MyClass && $x->isValid())` will now consistently check that isValid() exists on MyClass.
+ Improve analysis of expressions within conditionals, such as `if (!($x instanceof MyClass) || $x->method())`
  or `if (!(cond($x) && othercond($x)))`

  (Phan is now aware of the types of the right-hand side of `||` and `&&` in more cases)
+ Add many param and return type signatures for internal functions and methods,
  for params and return types that were previously untyped.
  (Imported from docs.php.net's SVN repo)
+ More precise analysis of the return types of `var_export()`, `print_r()`, and `json_decode()` (#1326, #1327)
+ Improve type narrowing from `iterable` to `\Traversable`/`array` (#1427)
  This change affects `is_array()`/`is_object()` checks and their negations.
+ Fix more edge cases which would cause Phan to fail to infer that properties, constants, or methods are inherited. (PR #1440 for issues #311, #1426, #454)

Plugins
+ Fix bugs in `NonBoolBranchPlugin` and `NonBoolInLogicalArithPlugin` (#1413, #1410)
+ **Make UnionType instances immutable.**
  This will affect plugins that used addType/addUnionType/removeType. withType/withUnionType/withoutType should be used instead.
  To modify the type of elements(properties, method return types, parameters, variables, etc),
  plugin authors should use `Element->setUnionType(plugin_modifier_function(Element->getUnionType()))`.

Language server:
+ Add a CLI option `--language-server-analyze-only-on-save` to prevent the client from sending change notifications. (#1325)
  (Only notify the language server when the user saves a document)
  This significantly reduces CPU usage, but clients won't get notifications about issues immediately.

Bug fixes
+ Warn when attempting to call an instance method on an expression with type string (#1314).
+ Fix a bug in `tool/make_stubs` when generating stubs of global functions.
+ Fix some bugs that occurred when Phan resolved inherited class constants in class elements such as properties. (#537 and #454)
+ Emit an issue when a function/method's parameter defaults refer to an undeclared class constant/global constant.

20 Jan 2018, Phan 0.11.1
------------------------

### Ported from Phan 0.10.3

New Features(CLI, Configs)

+ For `--fallback-parser`: Switch to [tolerant-php-parser](https://github.com/Microsoft/tolerant-php-parser)
  as a dependency of the fallback implementation. (#1125)
  This does a better job of generating PHP AST trees when attempting to parse code with a broader range of syntax errors.
  Keep `PHP-Parser` as a dependency for now for parsing strings.

Maintenance
+ Various performance optimizations, including caching of inferred union types to avoid unnecessary recalculation.
+ Make `phan_client` and the vim snippet in `plugins/vim/phansnippet.vim` more compatible with neovim
+ Upgrade felixfbecker/advanced-json-rpc dependency to ^3.0.0 (#1354)
+ Performance improvements.
  Changed the internal representation of union types to no longer require `spl_object_id` or the polyfill.

Bug Fixes
+ Allow `null` to be passed in where a union type of `mixed` was expected.
+ Don't warn when passing `?T` (PHPDoc or real) where the PHPDoc type was `T|null`. (#609, #1090, #1192, #1337)
  This is useful for expressions used for property assignments, return statements, function calls, etc.
+ Fix a few of Phan's signatures for internal functions and methods.

17 Nov 2017, Phan 0.11.0
------------------------

New Features (Analysis of PHP 7.2)
+ Support analyzing the `object` type hint in real function/method signatures. (#995)
+ Allow widening an overriding method's param types in php 7.2 branch (#1256)
  Phan continues warning about `ParamSignatureRealMismatchHasNoParamType` by default, in case a project needs to work with older php releases.
  Add `'allow_method_param_type_widening' => true` if you wish for Phan to stop emitting that issue category.
+ Miscellaneous function signature changes for analysis of PHP 7.2 codebases (#828)

### Ported from Phan 0.10.2

New Features(Analysis)
+ Enable `simplify_ast` by default.
  The new default value should reduce false positives when analyzing conditions of if statements. (#407, #1066)
+ Support less ambiguous `?(T[])` and `(?T)[]` in phpdoc (#1213)
  Note that `(S|T)[]` is **not** supported yet.
+ Support alternate syntax `array<T>` and `array<Key, T>` in phpdoc (PR #1213)
  Note that Phan ignores the provided value of `Key` completely right now (i.e. same as `T[]`); Key types will be supported in Phan 0.10.3.
+ Speed up Phan analysis on small projects, reduce memory usage (Around 0.15 seconds and 15MB)
  This was done by deferring loading the information about internal classes and functions until that information was needed for analysis.
+ Analyze existence and usage of callables passed to (internal and user-defined) function&methods expecting callable. (#1194)
  Analysis will now warn if the referenced function/method of a callable array/string
  (passed to a function/method expecting a callable param) does not exist.

  This change also reduces false positives in dead code detection (Passing in these callable arrays/strings counts as a reference now)
+ Warn if attempting to read/write to a property or constant when the expression is a non-object. (or not a class name, for static elements) (#1268)
+ Split `PhanUnreferencedClosure` out of `PhanUnreferencedFunction`. (Emitted by `--dead-code-detection`)
+ Split `PhanUnreferencedMethod` into `PhanUnreferencedPublicMethod`, `PhanUnreferencedProtectedMethod`, and `PhanUnreferencedPrivateMethod`.
+ Split errors for class constants out of `PhanUnreferencedConst`:
  Add `PhanUnreferencedPublicClassConst`, `PhanUnreferencedProtectedClassConst`, and `PhanUnreferencedPrivateClassConst`.
  `PhanUnreferencedConst` is now exclusively used for global constants.
+ Analyze uses of `compact()` for undefined variables (#1089)
+ Add `PhanParamSuspiciousOrder` to warn about mixing up variable and constant/literal arguments in calls to built in string/regex functions
  (`explode`, `strpos`, `mb_strpos`, `preg_match`, etc.)
+ Preserve the closure's function signature in the inferred return value of `Closure::bind()`. (#869)
+ Support indicating that a reference parameter's input value is unused by writing `@phan-output-reference` on the same line as an `@param` annotation.
  This indicates that Phan should not warn about the passed in type, and should not preserve the passed in type after the call to the function/method.
  (In other words, Phan will analyze a user-defined reference parameter the same way as it would `$matches` in `preg_match($pattern, $string, $matches)`)
  Example usage: `/** @param string $x @phan-output-reference */ function set_x(&$x) { $x = 'result'; }`
+ Make phan infer unreachability from fatal errors such as `trigger_error($message, E_USER_ERROR);` (#1224)
+ Add new issue types for places where an object would be expected:
  `PhanTypeExpectedObjectPropAccess`, `PhanTypeExpectedObjectPropAccessButGotNull`, `PhanTypeExpectedObjectStaticPropAccess`,
  `PhanTypeExpectedObject`, and `PhanTypeExpectedObjectOrClassName
+ Emit more accurate line numbers for phpdoc comments, when warning about phpdoc in doc comments being invalid. (#1294)
  This gives up and uses the element's line number if the phpdoc ends over 10 lines before the start of the element.
+ Work on allowing union types to be part of template types in doc comments,
  as well as types with template syntax.
  (e.g. `array<int|string>` is now equivalent to `int[]|string[]`,
  and `MyClass<T1|T2,T3|T4>` can now be parsed in doc comments)
+ Disambiguate the nullable parameter in output.
  E.g. an array of nullable integers will now be printed in error messages as `(?int)[]`
  A nullable array of integers will continue to be printed in error messages as `?int[]`, and can be specified in PHPDoc as `?(int[])`.

New Features (CLI, Configs)
+ Improve default update rate of `--progress-bar` (Update it every 0.10 seconds)

Bug Fixes
+ Fixes bugs in `PrintfCheckerPlugin`: Alignment goes before width, and objects with __toString() can cast to %s. (#1225)
+ Reduce false positives in analysis of gotos, blocks containing gotos anywhere may do something other than return or throw. (#1222)
+ Fix a crash when a magic method with a return type has the same name as a real method.
+ Allow methods to have weaker PHPdoc types than the overridden method in `PhanParamSignatureMismatch`. (#1253)
  `PhanParamSignatureRealMismatch*` is unaffected, and will continue working the same way in Phan releases analyzing PHP < 7.2.
+ Stop warning about `PhanParamSignatureMismatch`, etc. for private methods. The private methods don't affect each other. (#1250)
+ Properly parse `?self` as a *nullable* instance of the current class in union types (#1264)
+ Stop erroneously warning about inherited constants being unused in subclasses for dead code detection (#1260)
+ For dead code detection, properly track uses of inherited class elements (methods, properties, classes) as uses of the original definition. (#1108)
  Fix the way that uses of private/protected methods from traits were tracked.
  Also, start warning about a subset of issues from interfaces and abstract classes (e.g. unused interface constants)
+ Properly handle `static::class` as a class name in an array callable, or `static::method_name` in a string callable (#1232)
+ Make `@template` tag for [Generic Types](https://github.com/phan/phan/wiki/Generic-Types) case-sensitive. (#1243)
+ Fix a bug causing Phan to infer an empty union type (which can cast to any type) for arrays with elements of empty union types. (#1296)

Plugins
+ Make DuplicateArrayKeyPlugin start warning about duplicate values of known global constants and class constants. (#1139)
+ Make DuplicateArrayKeyPlugin start warning about case statements with duplicate values (This resolves constant values the same way as array key checks)
+ Support `'plugins' => ['AlwaysReturnPlugin']` as shorthand for full relative path to a bundled plugin such as AlwaysReturnPlugin.php (#1209)

Maintenance
+ Performance improvements: Phan analysis is 13%-22% faster than 0.10.1, with `simplify_ast` enabled.
+ Used PHP_CodeSniffer to automatically make Phan's source directory adhere closely to PSR-1 and PSR-2, making minor changes to many files.
  (e.g. which line each brace goes on, etc.)
+ Stop tracking references to internal (non user-defined) elements (constants, properties, functions, classes, and methods) during dead code detection.
  (Dead code detection now requires an extra 15MB instead of 17MB for self-analysis)

20 Oct 2017, Phan 0.10.1
------------------------

New Features(Analysis)
+ Support `@return $this` in phpdoc for methods and magic methods.
  (but not elsewhere. E.g. `@param $this $varName` is not supported, use `@param static $varName`) (#634)
+ Check if functions/methods passed to `array_map` and `array_filter` are compatible with their arguments.
  Recursively analyze the functions/methods passed to `array_map`/`array_filter` if no types were provided. (unless quick mode is being used)

New Features (CLI, Configs)

+ Add Language Server Protocol support (Experimental) (#821)
  Compatibility: Unix, Linux (depends on php `pcntl` extension).
  This has the same analysis capabilities provided by [daemon mode](https://github.com/phan/phan/wiki/Using-Phan-Daemon-Mode#using-phan_client-from-an-editor).
  Supporting a standard protocol should make it easier to write extensions supporting Phan in various IDEs.
  See https://github.com/Microsoft/language-server-protocol/blob/master/README.md
+ Add config (`autoload_internal_extension_signatures`) to allow users to specify PHP extensions (modules) used by the analyzed project,
  along with stubs for Phan to use (instead of ReflectionFunction, etc) if the PHP binary used to run Phan doesn't have those extensions enabled. (#627)
  Add a script (`tool/make_stubs`) to output the contents of stubs to use for `autoload_internal_extension_signatures` (#627).
+ By default, automatically restart Phan without Xdebug if Xdebug is enabled. (#1161)
  If you wish to analyze a project using Xdebug's functions, set `autoload_internal_extension_signatures`
  (e.g. `['xdebug' => 'vendor/phan/phan/.phan/internal_stubs/xdebug.phan_php']`)
  If you wish to use Xdebug to debug Phan's analysis itself, set and export the environment variable `PHAN_ALLOW_XDEBUG=1`.
+ Improve analysis of return types of `array_pop`, `array_shift`, `current`, `end`, `next`, `prev`, `reset`, `array_map`, `array_filter`, etc.
  See `ArrayReturnTypeOverridePlugin.php.`
  Phan can analyze callables (for `array_map`/`array_filter`) of `Closure` form, as well as strings/2-part arrays that are inlined.
+ Add `--memory-limit` CLI option (e.g. `--memory-limit 500M`). If this option isn't provided, there is no memory limit. (#1148)

Maintenance
+ Document the `--disable-plugins` CLI flag.

Plugins
+ Add a new plugin capability `ReturnTypeOverrideCapability` which can override the return type of functions and methods on a case by case basis.
  (e.g. based on one or more of the argument types or values) (related to #612, #1181)
+ Add a new plugin capability `AnalyzeFunctionCallCapability` which can add logic to analyze calls to a small subset of functions.
  (e.g. based on one or more of the argument types or values) (#1181)
+ Make line numbers more accurate in `DuplicateArrayKeyPlugin`.
+ Add `PregRegexCheckerPlugin` to check for invalid regexes. (uses `AnalyzeFunctionCallCapability`).
  This plugin is able to resolve literals, global constants, and class constants as regexes.
  See [the corresponding section of .phan/plugins/README.md](.phan/plugins/README.md#pregregexcheckerpluginphp)
+ Add `PrintfCheckerPlugin` to check for invalid format strings or incorrect arguments in printf calls. (uses `AnalyzeFunctionCallCapability`)
  This plugin is able to resolve literals, global constants, and class constants as format strings.
  See [the corresponding section of .phan/plugins/README.md](.phan/plugins/README.md#printfcheckerpluginphp)

Bug Fixes
+ Properly check for undeclared classes in arrays within phpdoc `@param`, `@property`, `@method`, `@var`, and `@return` (etc.) types.
  Also, fix a bug in resolving namespaces of generic arrays that are nested 2 or more array levels deep.
+ Fix uncaught TypeError when magic property has the same name as a property. (#1141)
+ Make AlwaysReturnPlugin warn about functions/methods with real nullable return types failing to return a value.
+ Change the behavior of the `-d` flag, make it change the current working directory to the provided directory.
+ Properly set the real param type and return types of internal functions, in rare cases where that exists.
+ Support analyzing the rare case of namespaced internal global functions (e.g. `\ast\parse_code($code, $version)`)
+ Improve analysis of shorthand ternary operator: Remove false/null from cond_expr in `(cond_expr) ?: (false_expr)` (#1186)

24 Sep 2017, Phan 0.10.0
------------------------

New Features(Analysis)
+ Check types of dimensions when using array access syntax (#406, #1093)
  (E.g. for an `array`, check that the array dimension can cast to `int|string`)

New Features (CLI, Configs)
+ Add option `ignore_undeclared_functions_with_known_signatures` which can be set to `false`
  to always warn about global functions Phan has signatures for
  but are unavailable in the current PHP process (and enabled extensions, and the project being analyzed) (#1080)
  The default was/is to not warn, to reduce false positives.
+ Add CLI flag `--use-fallback-parser` (Experimental).  If this flags is provided, then when Phan analyzes a syntactically invalid file,
  it will try again with a parser which tolerates a few types of errors, and analyze the statements that could be parsed.
  Useful in combination with daemon mode.
+ Add `phpdoc_type_mapping` config setting.
  Projects can override this to make Phan ignore or substitute non-standard phpdoc2 types and common typos (#294)
  (E.g. `'phpdoc_type_mapping' => ['the' => '', 'unknown_type' => '', 'number' => 'int|float']`)

Maintenance
+ Increased minimum `ext-ast` version constraint to 0.1.5, switched to AST version 50.
+ Update links to project from github.com/etsy/phan to github.com/phan/phan.
+ Use the native `spl_object_id` function if it is available for the union type implementation.
  This will make phan 10% faster in PHP 7.2.
  (for PHP 7.1, https://github.com/runkit7/runkit_object_id 1.1.0+ also provides a native implementation of `spl_object_id`)
+ Reduce memory usage by around 5% by tracking only the file and lines associated with variables, instead of a full Context object.


Plugins
+ Increased minimum `ext-ast` version constraint to 0.1.5, switched to AST version 50.
  Third party plugins will need to create a different version, Decls were changed into regular Nodes
+ Implement `AnalyzePropertyCapability` and `FinalizeProcessCapability`.
  Make `UnusedSuppressionPlugin` start using `AnalyzePropertyCapability` and `FinalizeProcessCapability`.
  Fix bug where `UnusedSuppressionPlugin` could run before the suppressed issues would be emitted,
  making it falsely emit that suppressions were unused.

Bug Fixes
+ Fix a few incorrect property names for Phan's signatures of internal classes (#1085)
+ Fix bugs in lookup of relative and non-fully qualified class and function names (#1097)
+ Fix a bug affecting analysis of code when `simplify_ast` is true.
+ Fix uncaught NodeException when analyzing complex variables as references (#1116),
  e.g. `function_expecting_reference($$x)`.

15 Aug 2017, Phan 0.9.4
-----------------------

New Features (Analysis)
+ Check (the first 5) elements of returned arrays against the declared return union types, individually (Issue #935)
  (E.g. `/** @return int[] */ function foo() {return [2, "x"]; }` will now warn with `PhanTypeMismatchReturn` about returning `string[]`)
+ Check both sides of ternary conditionals against the declared return union types
  (E.g. `function foo($x) : int {return is_string($x) ? $x : 0; }` will now warn with `PhanTypeMismatchReturn`
  about returning a string).
+ Improved analysis of negations of conditions within ternary conditional operators and else/else if statements. (Issue #538)
  Support analysis of negation of the `||` operator. (E.g. `if (!(is_string($x) || is_int($x))) {...}`)
+ Make phan aware of blocks of code which will unconditionally throw or return. (Issue #308, #817, #996, #956)

  Don't infer variable types from blocks of code which unconditionally throw or return.

  Infer the negation of type assertions from if statements that unconditionally throw/return/break/continue.
  (E.g. `if (!is_string($x)) { return false; } functionUsingX($x);`)

  When checking if a variable is defined by all branches of an if statement, ignore branches which unconditionally throw/return/break/continue.
+ To reduce the false positives from analysis of the negation of type assertions,
  normalize nullable/boolean union types after analyzing code branches (E.g. if/else) affecting the types of those variables.
  (e.g. convert "bool|false|null" to "?bool")
+ Add a new plugin file `AlwaysReturnPlugin`. (Issue #996)
  This will add a stricter check that a function with a non-null return type *unconditionally* returns a value (or explicitly throws, or exit()s).
  Currently, Phan just checks if a function *may* return, or unconditionally throws.
+ Add a new plugin file `UnreachableCodePlugin` (in development).
  This will warn about statements that appear to be unreachable
  (statements occurring after unconditional return/break/throw/return/exit statements)

New Features (CLI, Configs)
+ Add config setting `prefer_narrowed_phpdoc_return_type` (See "New Features (CLI, Configs)),
  which will use only the phpdoc return types for inferences, if they're narrowed.
  This config is enabled by default, and requires `check_docblock_signature_return_type_match` to be enabled.

Bug Fixes
+ Work around notice about COMPILER_HALT_OFFSET on Windows.
+ Fixes #462 : Fix type inferences for instanceof for checks with dynamic class names are provided.
  Valid class names are either a string or an instance of the class to check against.
  Warn if the class name is definitely invalid.
+ Fix false positives about undefined variables in isset()/empty() (Issue #1039)
  (Fixes bug introduced in Phan 0.9.3)
+ Fix false positive warnings about accessing protected methods from traits (Issue #1033)
  Act as though the class which used a trait is the place where the method was defined,
  so that method visibility checks work properly.
  Additionally, fix false positive warnings about visibility of method aliases from traits.
+ Warn about instantiation of class with inaccessible constructor (Issue #1043)
+ Fix rare uncaught exceptions (Various)
+ Make issues and plugin issues on properties consistently use suppressions from the plugin doc comment.

Changes In Emitted Issues
+ Improve `InvalidVariableIssetPlugin`. Change the names and messages for issue types.
  Emit `PhanPluginUndeclaredVariableInIsset` and `PhanPluginComplexVariableIsset`
  instead of `PhanUndeclaredVariable`.
  Stop erroneously warning about valid property fetches and checks of fields of superglobals.

0.9.3 Jul 11, 2017
------------------

New Features (Analysis)
+ Automatically inherit `@param` and `@return` types from parent methods.
  This is controlled by the boolean config `inherit_phpdoc_types`, which is true by default.
  `analyze_signature_compatibility` must also be set to true (default is true) for this step to be performed.
+ Better analysis of calls to parent::__construct(). (Issue #852)
+ Warn with `PhanAccessOwnConstructor` if directly invoking self::__construct or static::__construct in some cases (partial).
+ Start analyzing the inside of for/while loops using the loop's condition (Issue #859)
  (Inferences may leak to outside of those loops. `do{} while(cond)` is not specially analyzed yet)
+ Improve analysis of types in expressions within compound conditions (Issue #847)
  (E.g. `if (is_array($x) && fn_expecting_array($x)) {...}`)
+ Evaluate the third part of a for loop with the context after the inner body is evaluated (Issue #477)
+ Emit `PhanUndeclaredVariableDim` if adding an array field to an undeclared variable. (Issue #841)
  Better analyze `list($var['field']) = values`
+ Improve accuracy of `PhanTypeMismatchDeclaredReturn` (Move the check to after parse phase is finished)
+ Enable `check_docblock_signature_return_type_match` and `check_docblock_signature_param_type_match` by default.
  Improve performance of those checks.
  Switch to checking individual types (of the union type) of the phpdoc types and emitting issues for each invalid part.
+ Create `PhanTypeMismatchDeclaredParam` (Move the check to after parse phase is finished)
  Also add config setting `prefer_narrowed_phpdoc_param_type` (See "New Features (CLI, Configs))
  This config is enabled by default.

  Also create `PhanTypeMismatchDeclaredParamNullable` when params such as `function foo(string $x = null)`
  are documented as the narrowed forms `@param null $x` or `@param string $x`.
  Those should be changed to either `string|null` or `?string`.
+ Detect undeclared return types at point of declaration, and emit `PhanUndeclaredTypeReturnType` (Issue #835)
+ Create `PhanParamSignaturePHPDocMismatch*` issue types, for mismatches between `@method` and real signature/other `@method` tag.
+ Create `PhanAccessWrongInheritanceCategory*` issue types to warn about classes extending a trait/interface instead of class, etc. (#873)
+ Create `PhanExtendsFinalClass*` issue types to warn about classes extending from final classes.
+ Create `PhanAccessOverridesFinalMethod*` issue types to warn about methods overriding final methods.
+ Create `PhanTypeMagicVoidWithReturn` to warn if `void` methods such as `__construct`, `__set`, etc return a value that would be ignored. (Issue #913)
+ Add check for `PhanTypeMissingReturn` within closures. Properly emit `PhanTypeMissingReturn` in functions/methods containing closures. (Issue #599)
+ Improved checking for `PhanUndeclaredVariable` in array keys and conditional conditions. (Issue #912)
+ Improved warnings and inferences about internal function references for functions such as `sort`, `preg_match` (Issue #871, #958)
  Phan is now aware of many internal functions which normally ignore the original values of references passed in (E.g. `preg_match`)
+ Properly when code attempts to access static/non-static properties as if they were non-static/static. (Issue #936)
+ Create `PhanCommentOverrideOnNonOverrideMethod` and `PhanCommentOverrideOnNonOverrideConstant`. (Issue #926)
  These issue types will be emitted if `@override` is part of doc comment of a method or class constant which doesn't override or implement anything.
  (`@Override` and `@phan-override` can also be used as aliases of `@override`. `@override` is not currently part of any phpdoc standard.)
+ Add `@phan-closure-scope`, which can be used to annotate closure definitions with the namespaced class it will be bound to (Issue #309, #590, #790)
  (E.g. if the intent was that Closure->bindTo or Closure->bind would be called to bind it to `\MyNS\MyClass` (or an instance of that class),
  then a closure could be declared as `/** @phan-closure-scope \MyNS\MyClass */ function() { $this->somePrivateMyClassMethod(); }`
+ Add `Closure` as a first class type, (Previously, closures were treated as `callable` in some places) (Issue #978)

New Features (CLI, Configs)
+ Create `check_docblock_signature_param_type_match` (similar to `check_docblock_signature_return_type_match`) config setting
  to enable warning if phpdoc types are incompatible with the real types. True(enabled) by default.

  Create `prefer_narrowed_phpdoc_param_type` config setting (True by default, requires `check_docblock_signature_return_type_match` to be enabled).
  When it is true, Phan will analyze each function using the phpdoc param types instead of the provided signature types
  if the possible phpdoc types are narrower and compatible with the signature.
  (E.g. indicate that subclasses are expected over base classes, indicate that non-nullable is expected instead of nullable)
  This affects analysis both inside and outside the method.

  Aside: Phan currently defaults to preferring phpdoc type over real return type, and emits `PhanTypeMismatchDeclaredReturn` if the two are incompatible.
+ Create `enable_class_alias_support` config setting (disabled by default), which enables analyzing basic usage of class_alias. (Issue #586)
  Set it to true to enable it.
  NOTE: this is still experimental.
+ Warn to stderr about running Phan analysis with Xdebug (Issue #116)
  The warning can be disabled by the Phan config setting `skip_slow_php_options_warning` to true.
+ Add a config setting 'scalar_implicit_partial' to allow moving away from 'scalar_implicit_cast' (Issue #541)
  This allows users to list out (and gradually remove) permitted scalar type casts.
+ Add `null_casts_as_array` and `array_casts_as_null` settings, which can be used while migrating away from `null_casts_as_any_type`.
  These will be checked if one of the types has a union type of `null`, as well as when checking if a nullable array can cast to a regular array.

Plugins

+ Redesign plugin system to be more efficient. (Issue #600)
  New plugins should extend `\Phan\PluginV2` and implement the interfaces for capabilities they need to have,
  such as `\Phan\PluginV2\AnalyzeClassCapability`.
  In the new plugin system, plugins will only be run when they need to (Phan no longer needs to invoke an empty method body).
  Old subclasses of `\Phan\Plugin\PluginImplementation` will continue to work, but will be less efficient.

Maintenance
+ Reduce memory usage by around 15% by using a more efficient representation of union types (PR #729).
  The optional extension https://github.com/runkit7/runkit_object_id can be installed to boost performance by around 10%.
+ Check method signatures compatibility against all overridden methods (e.g. interfaces with the same methods), not just the first ones (Issue #925)

Bug Fixes
+ Work around known bugs in current releases of two PECL extensions (Issue #888, #889)
+ Fix typo - Change `PhanParamSignatureRealMismatch` to `PhanParamSignatureRealMismatchReturnType`
+ Consistently exit with non-zero exit code if there are multiple processes, and any process failed to return valid results. (Issue #868)
+ Fixes #986 : PhanUndeclaredVariable used to fail to be emitted in some deeply nested expressions, such as `return $undefVar . 'suffix';`
+ Make Phan infer the return types of closures, both for closures invoked inline and closures declared then invoked later (Issue #564)
+ Phan now correctly analyze global functions for mismatches of phpdoc types and real parameter types.
  Previously, it wouldn't emit warnings for global functions, only for methods.
+ Don't add `mixed` to inferred union types of properties which already have non-empty phpdoc types. (Issue #512)
  mixed would just result in Phan failing to emit any types of issues.
+ When `simplify_ast` is true, simplify the ASTs parsed in the parse mode as well.
  Makes analysis consistent when `quick_mode` is false (AST nodes from the parse phase would also be used in the analysis phase)
+ Don't emit PhanTypeNonVarPassByRef on arguments that are function/method calls returning references. (Issue #236)
+ Emit PhanContextNotObject more reliably when not in class scope.

Backwards Incompatible Changes
+ Fix categories of some issue types, renumber error ids for the pylint error formatter to be unique and consistent.

0.9.2 Jun 13, 2017
------------------

New Features (Analysis)
+ Add `PhanParamSignatureRealMismatch*` (e.g. `ParamSignatureRealMismatchTooManyRequiredParameters`),
  which ignores phpdoc types and imitates PHP's inheritance warning/error checks as closely as possible. (Issue #374)
  This has a much lower rate of false positives than `PhanParamSignatureMismatch`, which is based on Liskov Substitution Principle and also accounts for phpdoc types.
  (`PhanParamSignatureMismatch` continues to exist)
+ Create `PhanUndeclaredStaticProperty` (Issue #610)
  This is of higher severity than PhanUndeclaredProperty, because PHP 7 throws an Error.
  Also add `PhanAccessPropertyStaticAsNonStatic`
+ Supports magic instance/static `@method` annotations. (Issue #467)
  This is enabled by default.
+ Change the behavior of non-quick recursion (Affects emitted issues in large projects).
  Improve performance of non-quick analysis by checking for redundant analysis steps
  (E.g. calls from two different places passing the same union types for each parameter),
  continuing to recurse when passing by reference.
+ Support for checking for misuses of "@internal" annotations. Phan assumes this means it is internal to a namespace. (Issue #353)
  This checks properties, methods, class constants, and classes.
  (Adds `PhanAccessConstantInternal`, `PhanAccessClassInternal`, `PhanAccessClassConstantInternal`, `PhanAccessPropertyInternal`, `PhanAccessMethodInternal`)
  (The implementation may change)
+ Make conditionals such as `is_string` start applying to the condition in ternary operators (`$a ? $b : $c`)
+ Treat `resource`, `object`, and `mixed` as native types only when they occur in phpdoc.
  Outside of phpdoc (e.g. `$x instanceof resource`), analyze those names as if they were class names.
+ Emit low severity issues if Phan can't extract types from phpdoc,
  the phpdoc `@param` is out of sync with the code,
  or if the phpdoc annotation doesn't apply to an element type (Issue #778)
+ Allow inferring the type of variables from `===` conditionals such as `if ($x === true)`
+ Add issue type for non-abstract classes containing abstract methods from itself or its ancestors
  (`PhanClassContainsAbstractMethod`, `PhanClassContainsAbstractMethodInternal`)
+ Partial support for handling trait adaptations (`as`/`insteadof`) when using traits (Issue #312)
+ Start checking if uses of private/protected class methods *defined in a trait* are visible outside of that class.
  Before, Phan would always assume they were visible, to reduce false positives.
+ If Phan has inferred/been provided generic array types for a variable (e.g. `int[]`),
  then analysis of the code within `if (is_array($x))` will act as though the type is `int[]`.
  The checks `is_object` and `is_scalar` now also preserve known sub-types of the group of types.
  (If Phan isn't aware of any sub-types, it will infer the generic version, e.g. `object`)
+ Start checking if unanalyzable variable accesses such as `$$x` are very likely to be invalid or typos (e.g. $x is an object or array or null)
  Emit `PhanTypeSuspiciousIndirectVariable` if those are seen. (PR #809)
+ Add partial support for inferring the union types of the results of expressions such as `$x ^= 5` (e.g. in `foo($x ^= 5)`) (PR #809)
+ Thoroughly analyze the methods declared within traits,
  using only the information available within the trait. (Issue #800, PR #815)
  If new emitted issues are seen, users can (1) add abstract methods to traits, (2) add `@method` annotations, or (3) add `@suppress` annotations.

New Features (CLI, Configs)
+ (Linux/Unix only) Add Experimental Phan Daemon mode (PR #563 for Issue #22), which allows phan to run in the background, and accept TCP requests to analyze single files.
  (The implementation currently requires the `pcntl` extension, which does not in Windows)
  Server usage: `path/to/phan --daemonize-tcp-port 4846` (In the root directory of the project being analyzed)
  Client usage: `path/to/phan_client --daemonize-tcp-port 4846 -l src/file1.php [ -l src/file2.php ]`
+ Add `--color` CLI flag, with rudimentary unix terminal coloring for the plain text output formatter. (Issue #363)
  Color schemes are customizable with `color_scheme`, in the config file.
+ Add the `exclude_file_regex` config to exclude file paths based on a regular expression (e.g. tests or example files mixed with the codebase) (#635)
  The regular expression is run against the relative path within the project.
+ Add `--dump-parsed-file-list` option to print files which Phan would parse.
+ Add experimental `simplify_ast` config, to simplify the AST into a form which improves Phan's type inference.
  (E.g. handles some variable declarations within `if ()` statements.
   Infers that $x is a string for constructs such as `if (!is_string($x)) {return;} function_using_x($x);`)
  This is slow, and disabled by default.
+ Add `--include-analysis-file-list` option to define files that will be included in static analysis, to the exclusion of others.
+ Start emitting `PhanDeprecatedFunctionInternal` if an internal (to PHP) function/method is deprecated.
  (Phan emits `PhanUndeclaredFunction` if a function/method was removed; Functions deprecated in PHP 5.x were removed in 7.0)

Maintenance
+ Update function signature map to analyze `iterable` and `is_iterable` from php 7.1
+ Improve type inferences on functions with nullable default values.
+ Update miscellaneous new functions in php 7.1 standard library (e.g. `getenv`)

Bug Fixes
- Fix PhanTypeMismatchArgument, etc. for uses of `new static()`, static::CONST, etc in a method. (Issue #632)
- Fix uncaught exception when conditional node is a scalar (Issue #613)
- Existence of __get() no longer affects analyzing static properties. (Issue #610)
- Phan can now detect the declaration of constants relative to a `use`d namespace (Issue #509)
- Phan can now detect the declaration of functions relative to a `use`d namespace (Issue #510)
- Fix a bug where the JSON output printer accidentally escaped some output ("<"), causing invalid JSON.
- Fix a bug where a print/echo/method call erroneously marked methods/functions as having a return value. (Issue #811)
- Improve analysis of SimpleXMLElement (Issues #542, #539)
- Fix crash handling trait use aliases which change only the method's visibility (Issue #861)

Backwards Incompatible Changes
- Declarations of user-defined constants are now consistently
  analyzed in a case-sensitive way.
  This may affect projects using `define(name, value, case_insensitive = true)`.
  Change the code being analyzed to exactly match the constant name in define())

0.9.1 Mar 15, 2017
------------------

New Features (Analysis)
+ Conditions in `if(cond(A) && expr(A))` (e.g. `instanceof`, `is_string`, etc) now affect analysis of right-hand side of `&&` (PR #540)
+ Add `PhanDeprecatedInterface` and `PhanDeprecatedTrait`, similar to `PhanDeprecatedClass`
+ Supports magic `@property` annotations, with aliases `@property-read` and @property-write`. (Issue #386)
  This is enabled by default.

  Phan also supports the `@phan-forbid-undeclared-magic-properties` annotation,
  which will make it warn about undeclared properties if no real property or `@property` annotation exists.

New Features (CLI, Configs)
+ Add `--version` CLI flag
+ Move some rare CLI options from `--help` into `--extended-help`

Maintenance
+ Improved stability of analyzing phpdoc and real nullable types (Issue #567)
+ Fix type signatures Phan has for some internal methods.
+ Improve CLI `--progress-bar` tracking by printing 0% immediately.
+ Add Developer Certificate of Origin

Bug Fixes
+ Fix uncaught issue exception analyzing class constants (Issue #551)
+ Fix group use in ASTs
+ Fix false positives checking if native types can cast to/from nullable native types (Issue #567, #582)
+ Exit with non-zero exit code if an invalid CLI argument is passed to Phan

Backwards Incompatible Changes
+ Change the way that parameter's default values affect type inferences.
  (May now add to the union type or ignore default values. Used to always add the default value types)
  Add `@param` types if you encounter new issues.
  This was done to avoid false positives in cases such as `function foo($maybeArray = false)`
+ Increase minimum `ext-ast` version constraint to 0.1.4

0.9.0 Feb 21, 2017
------------------

The 0.9.x versions will be tracking syntax from PHP versions 7.1.x and is runnable on PHP 7.1+.
Please use version 0.8.x if you're using a version of PHP < 7.1.

New Features (Analysis)
+ Support php 7.1 class constant visibility
+ Support variadic phpdoc in `@param`, e.g. `@param string ...$args`
  Avoid ambiguity by emitting `PhanTypeMismatchVariadicComment` and `PhanTypeMismatchVariadicParam`.
+ Initial support for php 7.1 nullable types and void, both in phpdoc and real parameters.
+ Initial support for php 7.1 `iterable` type
+ Both conditions from `if(cond(A) && cond(B))` (e.g. `instanceof`, `is_string`, etc.) now affect analysis of the if element's block (PR #540)
+ Apply conditionals such as `is_string` to type guards in ternary operators (Issue #465)
+ Allow certain checks for removing null from Phan's inferred types, reducing false positives (E.g. `if(!is_null($x) && $x->method())`) (#518)
+ Incomplete support for specifying the class scope in which a closure will be used/bound  (#309)
+ Support `@return self` in class context

New Features (CLI, Configs)
+ Introduce `check_docblock_signature_return_type_match` config (slow, disabled by default)
  (Checks if the phpdoc types match up with declared return types)

Maintenance
+ Add Code of Conduct
+ Fix type signatures for some internal methods and internal class properties.

Bug Fixes
+ Allow asserting `object` is a specific object type without warning (Issue #516)
+ Fix bugs in analysis of varargs within a function(Issue #516)
+ Treat null defaults in functions and methods the same way (Issue #508)
  In both, add null defaults to the UnionType only if there's already another type.
  In both, add non-null defaults to the UnionType (Contains `mixed` if there weren't any explicit types)
+ Specially handle phpdoc type aliases such as `boolean` only in phpdoc (Issue #471)
  (Outside of phpdoc, it refers to a class with the name `boolean`)
+ Add some internal classes other than `stdClass` which are allowed to have dynamic, undeclared properties (Issue #433)
+ Fix assertion errors when passing references by reference (Issue #500)

Backwards Incompatible Changes
+ Requires newer `ext-ast` version (Must support version 35).

0.8.3 Jan 26, 2017
------------------

The 0.8.x versions will be tracking syntax from PHP versions 7.0.x and is runnable on PHP 7.0+.
Please use version 0.8.x if you're using a version of PHP < 7.1.
For best results, run version 0.8.x with PHP 7.0 if you are analyzing a codebase which normally runs on php <= 7.0
(If php 7.1 is used, Phan will think that some new classes, methods, and functions exist or have different parameter lists because it gets this info from `Reflection`)

???
