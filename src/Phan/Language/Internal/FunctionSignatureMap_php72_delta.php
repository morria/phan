<?php // phpcs:ignoreFile

/**
 * This contains the information needed to convert the function signatures for php 7.2 to php 7.1 (and vice versa)
 *
 * This has two sections.
 * The 'new' section contains function/method names from FunctionSignatureMap (And alternates, if applicable) that do not exist in php7.1 or have different signatures in php 7.2.
 *   If they were just updated, the function/method will be present in the 'added' signatures.
 * The 'old' signatures contains the signatures that are different in php 7.1.
 *   Functions are expected to be removed only in major releases of php. (e.g. php 7.0 removed various functions that were deprecated in 5.6)
 *
 * @see FunctionSignatureMap.php
 *
 * @phan-file-suppress PhanPluginMixedKeyNoKey (read by Phan when analyzing this file)
 */
return [
'new' => [
'DOMNodeList::count' => ['int'],
'ftp_append' => ['bool', 'ftp'=>'resource', 'remote_filename'=>'string', 'local_filename'=>'string', 'mode='=>'int'],
'hash_copy' => ['HashContext', 'context'=>'HashContext'],
'hash_final' => ['string', 'context'=>'HashContext', 'binary='=>'bool'],
'hash_hmac_algos' => ['list<string>'],
'hash_init' => ['HashContext', 'algo'=>'string', 'flags='=>'int', 'key='=>'string'],
'hash_update' => ['bool', 'context'=>'HashContext', 'data'=>'string'],
'hash_update_file' => ['bool', 'context'=>'HashContext', 'filename'=>'string', 'stream_context='=>'?resource'],
'hash_update_stream' => ['int', 'context'=>'HashContext', 'stream'=>'resource', 'length='=>'int'],
'imagebmp' => ['bool', 'image'=>'resource', 'file='=>'null|resource|string', 'compressed='=>'bool'],
'imagecreatefrombmp' => ['resource|false', 'filename'=>'string'],
'imageopenpolygon' => ['bool', 'image'=>'resource', 'points'=>'array', 'num_points_or_color'=>'int', 'color='=>'int'],
'imageresolution' => ['array<int,int>|bool', 'image'=>'resource', 'resolution_x='=>'int', 'resolution_y='=>'int'],
'imagesetclip' => ['bool', 'image'=>'resource', 'x1'=>'int', 'x2'=>'int', 'y1'=>'int', 'y2'=>'int'],
'ldap_exop' => ['bool|resource', 'ldap'=>'resource', 'request_oid'=>'string', 'request_data='=>'string', 'controls='=>'array', '&w_response_data='=>'string', '&w_response_oid='=>'string'],
'ldap_exop_passwd' => ['bool|string', 'ldap'=>'resource', 'user='=>'string', 'old_password='=>'string', 'new_password='=>'string', '&controls='=>'array'],
'ldap_exop_refresh' => ['int|false', 'ldap'=>'resource', 'dn'=>'string', 'ttl'=>'int'],
'ldap_exop_whoami' => ['string|false', 'ldap'=>'resource'],
'ldap_parse_exop' => ['bool', 'ldap'=>'resource', 'result'=>'resource', '&w_response_data='=>'string', '&w_response_oid='=>'string'],
'mb_chr' => ['string|false', 'codepoint'=>'int', 'encoding='=>'string'],
'mb_ord' => ['int|false', 'string'=>'string', 'encoding='=>'string'],
'mb_scrub' => ['string|false', 'string'=>'string', 'encoding='=>'string'],
'oci_register_taf_callback' => ['bool', 'connection'=>'resource', 'callback'=>'callable'],
'oci_set_call_timeout' => ['bool', 'connection'=>'resource', 'timeout'=>'int'],
'oci_unregister_taf_callback' => ['bool', 'connection'=>'resource'],
'ReflectionClass::isIterable' => ['bool'],
'SQLite3::openBlob' => ['resource|false', 'table'=>'string', 'column'=>'string', 'rowid'=>'int', 'dbname='=>'string', 'flags='=>'int'],
'sapi_windows_vt100_support' => ['bool', 'stream'=>'resource', 'enable='=>'bool'],
'socket_addrinfo_bind' => ['?resource', 'address'=>'resource'],
'socket_addrinfo_connect' => ['?resource', 'address'=>'resource'],
'socket_addrinfo_explain' => ['array', 'address'=>'resource'],
'socket_addrinfo_lookup' => ['resource[]', 'host'=>'string', 'service='=>'?string', 'hints='=>'array'],
'sodium_add' => ['void', '&string_1'=>'string', 'string_2'=>'string'],
'sodium_base642bin' => ['string', 'string_1'=>'string', 'id'=>'int', 'string_2='=>'string'],
'sodium_bin2base64' => ['string', 'string'=>'string', 'id'=>'int'],
'sodium_bin2hex' => ['string', 'string'=>'string'],
'sodium_compare' => ['int', 'string_1'=>'string', 'string_2'=>'string'],
'sodium_crypto_aead_aes256gcm_decrypt' => ['string|false', 'string'=>'string', 'ad'=>'string', 'nonce'=>'string', 'key'=>'string'],
'sodium_crypto_aead_aes256gcm_encrypt' => ['string', 'string'=>'string', 'ad'=>'string', 'nonce'=>'string', 'key'=>'string'],
'sodium_crypto_aead_aes256gcm_is_available' => ['bool'],
'sodium_crypto_aead_aes256gcm_keygen' => ['string'],
'sodium_crypto_aead_chacha20poly1305_decrypt' => ['string', 'string'=>'string', 'ad'=>'string', 'nonce'=>'string', 'key'=>'string'],
'sodium_crypto_aead_chacha20poly1305_encrypt' => ['string', 'string'=>'string', 'ad'=>'string', 'nonce'=>'string', 'key'=>'string'],
'sodium_crypto_aead_chacha20poly1305_ietf_decrypt' => ['?string|?false', 'string'=>'string', 'ad'=>'string', 'nonce'=>'string', 'key'=>'string'],
'sodium_crypto_aead_chacha20poly1305_ietf_encrypt' => ['string', 'string'=>'string', 'ad'=>'string', 'nonce'=>'string', 'key'=>'string'],
'sodium_crypto_aead_chacha20poly1305_ietf_keygen' => ['string'],
'sodium_crypto_aead_chacha20poly1305_keygen' => ['string'],
'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' => ['string|false', 'string'=>'string', 'ad'=>'string', 'nonce'=>'string', 'key'=>'string'],
'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' => ['string', 'string'=>'string', 'ad'=>'string', 'nonce'=>'string', 'key'=>'string'],
'sodium_crypto_aead_xchacha20poly1305_ietf_keygen' => ['string'],
'sodium_crypto_auth' => ['string', 'string'=>'string', 'key'=>'string'],
'sodium_crypto_auth_keygen' => ['string'],
'sodium_crypto_auth_verify' => ['bool', 'signature'=>'string', 'string'=>'string', 'key'=>'string'],
'sodium_crypto_box' => ['string', 'string'=>'string', 'nonce'=>'string', 'key'=>'string'],
'sodium_crypto_box_keypair' => ['string'],
'sodium_crypto_box_keypair_from_secretkey_and_publickey' => ['string', 'secret_key'=>'string', 'public_key'=>'string'],
'sodium_crypto_box_open' => ['string|false', 'string'=>'string', 'nonce'=>'string', 'key'=>'string'],
'sodium_crypto_box_publickey' => ['string', 'key'=>'string'],
'sodium_crypto_box_publickey_from_secretkey' => ['string', 'key'=>'string'],
'sodium_crypto_box_seal' => ['string', 'string'=>'string', 'key'=>'string'],
'sodium_crypto_box_seal_open' => ['string|false', 'string'=>'string', 'key'=>'string'],
'sodium_crypto_box_secretkey' => ['string', 'key'=>'string'],
'sodium_crypto_box_seed_keypair' => ['string', 'key'=>'string'],
'sodium_crypto_generichash' => ['string', 'string'=>'string', 'key='=>'string', 'length='=>'int'],
'sodium_crypto_generichash_final' => ['string', '&state'=>'string', 'length='=>'int'],
'sodium_crypto_generichash_init' => ['string', 'key='=>'string', 'length='=>'int'],
'sodium_crypto_generichash_keygen' => ['string'],
'sodium_crypto_generichash_update' => ['bool', '&state'=>'string', 'string'=>'string'],
'sodium_crypto_kdf_derive_from_key' => ['string', 'subkey_len'=>'int', 'subkey_id'=>'int', 'context'=>'string', 'key'=>'string'],
'sodium_crypto_kdf_keygen' => ['string'],
'sodium_crypto_kx_client_session_keys' => ['array<int,string>', 'client_keypair'=>'string', 'server_key'=>'string'],
'sodium_crypto_kx_keypair' => ['string'],
'sodium_crypto_kx_publickey' => ['string', 'key'=>'string'],
'sodium_crypto_kx_secretkey' => ['string', 'key'=>'string'],
'sodium_crypto_kx_seed_keypair' => ['string', 'string'=>'string'],
'sodium_crypto_kx_server_session_keys' => ['array<int,string>', 'server_keypair'=>'string', 'client_key'=>'string'],
'sodium_crypto_pwhash' => ['string', 'length'=>'int', 'password'=>'string', 'salt'=>'string', 'opslimit'=>'int', 'memlimit'=>'int', 'alg='=>'int'],
'sodium_crypto_pwhash_scryptsalsa208sha256' => ['string', 'length'=>'int', 'password'=>'string', 'salt'=>'string', 'opslimit'=>'int', 'memlimit'=>'int'],
'sodium_crypto_pwhash_scryptsalsa208sha256_str' => ['string', 'password'=>'string', 'opslimit'=>'int', 'memlimit'=>'int'],
'sodium_crypto_pwhash_scryptsalsa208sha256_str_verify' => ['bool', 'hash'=>'string', 'password'=>'string'],
'sodium_crypto_pwhash_str' => ['string', 'password'=>'string', 'opslimit'=>'int', 'memlimit'=>'int'],
'sodium_crypto_pwhash_str_needs_rehash' => ['bool', 'password'=>'string', 'opslimit'=>'int', 'memlimit'=>'int'],
'sodium_crypto_pwhash_str_verify' => ['bool', 'hash'=>'string', 'password'=>'string'],
'sodium_crypto_scalarmult' => ['string', 'string_1'=>'string', 'string_2'=>'string'],
'sodium_crypto_scalarmult_base' => ['string', 'string_1'=>'string'],
'sodium_crypto_secretbox' => ['string', 'string'=>'string', 'nonce'=>'string', 'key'=>'string'],
'sodium_crypto_secretbox_keygen' => ['string'],
'sodium_crypto_secretbox_open' => ['string|false', 'string'=>'string', 'nonce'=>'string', 'key'=>'string'],
'sodium_crypto_secretstream_xchacha20poly1305_init_pull' => ['string', 'string'=>'string', 'key'=>'string'],
'sodium_crypto_secretstream_xchacha20poly1305_init_push' => ['array', 'key'=>'string'],
'sodium_crypto_secretstream_xchacha20poly1305_keygen' => ['string'],
'sodium_crypto_secretstream_xchacha20poly1305_pull' => ['array', '&state'=>'string', 'ciphertext'=>'string', 'additional_data='=>'string'],
'sodium_crypto_secretstream_xchacha20poly1305_push' => ['string', '&state'=>'string', 'message'=>'string', 'additional_data='=>'string', 'tag='=>'int'],
'sodium_crypto_secretstream_xchacha20poly1305_rekey' => ['void', '&state'=>'string'],
'sodium_crypto_shorthash' => ['string', 'string'=>'string', 'key'=>'string'],
'sodium_crypto_shorthash_keygen' => ['string'],
'sodium_crypto_sign' => ['string', 'string'=>'string', 'keypair'=>'string'],
'sodium_crypto_sign_detached' => ['string', 'string'=>'string', 'keypair'=>'string'],
'sodium_crypto_sign_ed25519_pk_to_curve25519' => ['string', 'key'=>'string'],
'sodium_crypto_sign_ed25519_sk_to_curve25519' => ['string', 'key'=>'string'],
'sodium_crypto_sign_keypair' => ['string'],
'sodium_crypto_sign_keypair_from_secretkey_and_publickey' => ['string', 'secret_key'=>'string', 'public_key'=>'string'],
'sodium_crypto_sign_open' => ['string|false', 'string'=>'string', 'keypair'=>'string'],
'sodium_crypto_sign_publickey' => ['string', 'key'=>'string'],
'sodium_crypto_sign_publickey_from_secretkey' => ['string', 'key'=>'string'],
'sodium_crypto_sign_secretkey' => ['string', 'key'=>'string'],
'sodium_crypto_sign_seed_keypair' => ['string', 'key'=>'string'],
'sodium_crypto_sign_verify_detached' => ['bool', 'signature'=>'string', 'string'=>'string', 'key'=>'string'],
'sodium_crypto_stream' => ['string', 'length'=>'int', 'nonce'=>'string', 'key'=>'string'],
'sodium_crypto_stream_keygen' => ['string'],
'sodium_crypto_stream_xor' => ['string', 'string'=>'string', 'nonce'=>'string', 'key'=>'string'],
'sodium_hex2bin' => ['string', 'string_1'=>'string', 'string_2='=>'string'],
'sodium_increment' => ['void', '&string'=>'string'],
'sodium_memcmp' => ['int', 'string_1'=>'string', 'string_2'=>'string'],
'sodium_memzero' => ['void', '&reference'=>'string'],
'sodium_pad' => ['string', 'string'=>'string', 'length'=>'int'],
'sodium_unpad' => ['string', 'string'=>'string', 'length'=>'int'],
'stream_isatty' => ['bool', 'stream'=>'resource'],
'ZipArchive::count' => ['int'],
'ZipArchive::setEncryptionIndex' => ['bool', 'index'=>'int', 'method'=>'string', 'password='=>'string'],
'ZipArchive::setEncryptionName' => ['bool', 'name'=>'string', 'method'=>'int', 'password='=>'string'],
],
'old' => [
'hash_copy' => ['resource', 'context'=>'resource'],
'hash_final' => ['string', 'context'=>'resource', 'binary='=>'bool'],
'hash_init' => ['resource', 'algo'=>'string', 'flags='=>'int', 'key='=>'string'],
'hash_update' => ['bool', 'context'=>'resource', 'data'=>'string'],
'hash_update_file' => ['bool', 'context'=>'resource', 'filename'=>'string', 'stream_context='=>'?resource'],
'hash_update_stream' => ['int', 'context'=>'resource', 'stream'=>'resource', 'length='=>'int'],
'SQLite3::openBlob' => ['resource|false', 'table'=>'string', 'column'=>'string', 'rowid'=>'int', 'dbname='=>'string'],
],
];
