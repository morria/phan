<?php // phpcs:ignoreFile

/**
 * This contains the information needed to convert the function signatures for php 7.4 to php 7.3 (and vice versa)
 *
 * This has two sections.
 * The 'new' section contains function/method names from FunctionSignatureMap (And alternates, if applicable) that do not exist in php7.3 or have different signatures in php 7.4.
 *   If they were just updated, the function/method will be present in the 'added' signatures.
 * The 'old' signatures contains the signatures that are different in php 7.3.
 *   Functions are expected to be removed only in major releases of php.
 *
 * @see FunctionSignatureMap.php
 *
 * @phan-file-suppress PhanPluginMixedKeyNoKey (read by Phan when analyzing this file)
 */
return [
  'added' => [
    'DatePeriod::getRecurrences' => ['int'],
    'FFI::addr' => ['FFI\CData', '&ptr'=>'FFI\CData'],
    'FFI::alignof' => ['int', '&ptr'=>'mixed'],
    'FFI::arrayType' => ['FFI\CType', 'type'=>'string|FFI\CType', 'dimensions'=>'list<int>'],
    'FFI::cast' => ['FFI\CData', 'type'=>'string|FFI\CType', '&ptr'=>''],
    'FFI::cdef' => ['FFI', 'code='=>'string', 'lib='=>'?string'],
    'FFI::free' => ['void', '&ptr'=>'FFI\CData'],
    'FFI::isNull' => ['bool', '&ptr'=>'FFI\CData'],
    'FFI::load' => ['FFI', 'filename'=>'string'],
    'FFI::memcmp' => ['int', '&ptr1'=>'FFI\CData|string', '&ptr2'=>'FFI\CData|string', 'size'=>'int'],
    'FFI::memcpy' => ['void', '&to'=>'FFI\CData', '&from'=>'string|FFI\CData', 'size'=>'int'],
    'FFI::memset' => ['void', '&ptr'=>'FFI\CData', 'value'=>'int', 'size'=>'int'],
    'FFI::new' => ['FFI\CData', 'type'=>'string|FFI\CType', 'owned='=>'bool', 'persistent='=>'bool'],
    'FFI::scope' => ['FFI', 'name'=>'string'],
    'FFI::sizeof' => ['int', '&ptr'=>'FFI\CData|FFI\CType'],
    'FFI::string' => ['string', '&ptr'=>'FFI\CData', 'size='=>'int'],
    'FFI::type' => ['FFI\CType', 'type'=>'string'],
    'FFI::typeof' => ['FFI\CType', '&ptr'=>'FFI\CData'],
    'get_mangled_object_vars' => ['array', 'object'=>'object'],
    'imagecreatefromtga' => ['resource|false', 'filename'=>'string'],
    'openssl_x509_verify' => ['int', 'certificate'=>'string|resource', 'public_key'=>'string|resource'],
    'password_algos' => ['list<string>'],
    'pcntl_unshare' => ['bool', 'flags'=>'int'],
    'ReflectionReference::fromArrayElement' => ['?ReflectionReference', 'array'=>'array', 'key'=>'int|string'],
    'ReflectionReference::getId' => ['string'],
    'sapi_windows_set_ctrl_handler' => ['bool', 'handler'=>'callable(int):void', 'add='=>'bool'],
    'SQLite3::backup' => ['bool', 'destination'=>'SQLite3', 'sourceDatabase='=>'string', 'destinationDatabase='=>'string'],
    'SQLite3Stmt::getSQL' => ['string', 'expand='=>'bool'],
    'WeakReference::create' => ['WeakReference', 'object'=>'object'],
    'WeakReference::get' => ['?object'],
  ],
  'changed' => [
    'array_push' => [
      'old' => ['int', '&rw_array'=>'array', 'values'=>'mixed', '...vars='=>'mixed'],
      'new' => ['int', '&rw_array'=>'array', '...values='=>'mixed'],
    ],
    'array_unshift' => [
      'old' => ['int', '&rw_array'=>'array', 'values'=>'mixed', '...vars='=>'mixed'],
      'new' => ['int', '&rw_array'=>'array', '...values='=>'mixed'],
    ],
    'password_hash' => [
      'old' => ['string|false|null', 'password'=>'string', 'algo'=>'int', 'options='=>'array'],
      'new' => ['string|false|null', 'password'=>'string', 'algo'=>'?string|?int', 'options='=>'array'],
    ],
    'password_needs_rehash' => [
      'old' => ['bool', 'hash'=>'string', 'algo'=>'int', 'options='=>'array'],
      'new' => ['bool', 'hash'=>'string', 'algo'=>'?string|?int', 'options='=>'array'],
    ],
    'preg_replace_callback' => [
      'old' => ['string|string[]|null', 'pattern'=>'string|array', 'callback'=>'callable(array):string', 'subject'=>'string|array', 'limit='=>'int', '&w_count='=>'int'],
      'new' => ['string|string[]|null', 'pattern'=>'string|array', 'callback'=>'callable(array):string', 'subject'=>'string|array', 'limit='=>'int', '&w_count='=>'int', 'flags='=>'int'],
    ],
    'preg_replace_callback_array' => [
      'old' => ['string|string[]|null', 'pattern'=>'array<string,callable(array):string>', 'subject'=>'string|array', 'limit='=>'int', '&w_count='=>'int'],
      'new' => ['string|string[]|null', 'pattern'=>'array<string,callable(array):string>', 'subject'=>'string|array', 'limit='=>'int', '&w_count='=>'int', 'flags='=>'int'],
    ],
    'proc_open' => [
      'old' => ['resource|false', 'command'=>'string', 'descriptor_spec'=>'array', '&w_pipes'=>'resource[]', 'cwd='=>'?string', 'env_vars='=>'?array', 'options='=>'array'],
      'new' => ['resource|false', 'command'=>'string|string[]', 'descriptor_spec'=>'array', '&w_pipes'=>'resource[]', 'cwd='=>'?string', 'env_vars='=>'?array', 'options='=>'array'],
    ],
    'strip_tags' => [
      'old' => ['string', 'string'=>'string', 'allowed_tags='=>'string'],
      'new' => ['string', 'string'=>'string', 'allowed_tags='=>'string|string[]'],
    ],
  ],
  'removed' => [
  ],
];
