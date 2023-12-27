<?php

declare(strict_types=1);

use Phan\CodeBase;
use Phan\Config;
use Phan\Language\FQSEN\FullyQualifiedFunctionName;
use Phan\PluginV3;
use Phan\PluginV3\BeforeAnalyzePhaseCapability;

/**
 * This plugin deprecates aliases of global functions.
 *
 * See https://www.php.net/manual/en/aliases.php
 */
class DeprecateAliasPlugin extends PluginV3 implements
    BeforeAnalyzePhaseCapability
{
    /**
     * Source: https://www.php.net/manual/en/aliases.php
     * TODO: Extract from php signatures instead?
     */
    const KNOWN_ALIASES = [
        // Deliberately not warning about `_` given how common it can be and unlikeliness of it being deprecated in the future.
        // TODO: Provide ways to add or remove aliases to warn about?
        //'_' => 'gettext',
        'add' => 'swfmovie_add',
        //'add' => 'swfsprite_add and others',
        'addaction' => 'swfbutton_addAction',
        'addcolor' => 'swfdisplayitem_addColor',
        'addentry' => 'swfgradient_addEntry',
        'addfill' => 'swfshape_addfill',
        'addshape' => 'swfbutton_addShape',
        'addstring' => 'swftext_addString and others',
        //'addstring' => 'swftextfield_addString',
        'align' => 'swftextfield_align',
        'chop' => 'rtrim',
        'close' => 'closedir',
        'com_get' => 'com_propget',
        'com_propset' => 'com_propput',
        'com_set' => 'com_propput',
        'die' => 'exit',
        'diskfreespace' => 'disk_free_space',
        'doubleval' => 'floatval',
        'drawarc' => 'swfshape_drawarc',
        'drawcircle' => 'swfshape_drawcircle',
        'drawcubic' => 'swfshape_drawcubic',
        'drawcubicto' => 'swfshape_drawcubicto',
        'drawcurve' => 'swfshape_drawcurve',
        'drawcurveto' => 'swfshape_drawcurveto',
        'drawglyph' => 'swfshape_drawglyph',
        'drawline' => 'swfshape_drawline',
        'drawlineto' => 'swfshape_drawlineto',
        'fbsql' => 'fbsql_db_query',
        'fputs' => 'fwrite',
        'getascent' => 'swffont_getAscent and others',
        //'getascent' => 'swftext_getAscent',
        'getdescent' => 'swffont_getDescent and others',
        //'getdescent' => 'swftext_getDescent',
        'getheight' => 'swfbitmap_getHeight',
        'getleading' => 'swffont_getLeading and others and others',
        //'getleading' => 'swftext_getLeading',
        'getshape1' => 'swfmorph_getShape1',
        'getshape2' => 'swfmorph_getShape2',
        'getwidth' => 'swfbitmap_getWidth and others',
        //'getwidth' => 'swffont_getWidth',
        //'getwidth' => 'swftext_getWidth',
        'gzputs' => 'gzwrite',
        'i18n_convert' => 'mb_convert_encoding',
        'i18n_discover_encoding' => 'mb_detect_encoding',
        'i18n_http_input' => 'mb_http_input',
        'i18n_http_output' => 'mb_http_output',
        'i18n_internal_encoding' => 'mb_internal_encoding',
        'i18n_ja_jp_hantozen' => 'mb_convert_kana',
        'i18n_mime_header_decode' => 'mb_decode_mimeheader',
        'i18n_mime_header_encode' => 'mb_encode_mimeheader',
        'imap_create' => 'imap_createmailbox',
        'imap_fetchtext' => 'imap_body',
        'imap_getmailboxes' => 'imap_list_full',
        'imap_getsubscribed' => 'imap_lsub_full',
        'imap_header' => 'imap_headerinfo',
        'imap_listmailbox' => 'imap_list',
        'imap_listsubscribed' => 'imap_lsub',
        'imap_rename' => 'imap_renamemailbox',
        'imap_scan' => 'imap_listscan',
        'imap_scanmailbox' => 'imap_listscan',
        'ini_alter' => 'ini_set',
        'is_double' => 'is_float',
        'is_integer' => 'is_int',
        'is_long' => 'is_int',
        'is_real' => 'is_float',
        'is_writeable' => 'is_writable',
        'join' => 'implode',
        'key_exists' => 'array_key_exists',
        'labelframe' => 'swfmovie_labelFrame and others',
        //'labelframe' => 'swfsprite_labelFrame',
        'ldap_close' => 'ldap_unbind',
        'magic_quotes_runtime' => 'set_magic_quotes_runtime',
        'mbstrcut' => 'mb_strcut',
        'mbstrlen' => 'mb_strlen',
        'mbstrpos' => 'mb_strpos',
        'mbstrrpos' => 'mb_strrpos',
        'mbsubstr' => 'mb_substr',
        'ming_setcubicthreshold' => 'ming_setCubicThreshold',
        'ming_setscale' => 'ming_setScale',
        'move' => 'swfdisplayitem_move',
        'movepen' => 'swfshape_movepen',
        'movepento' => 'swfshape_movepento',
        'moveto' => 'swfdisplayitem_moveTo and others',
        //'moveto' => 'swffill_moveTo',
        //'moveto' => 'swftext_moveTo',
        'msql' => 'msql_db_query',
        'msql_createdb' => 'msql_create_db',
        'msql_dbname' => 'msql_result',
        'msql_dropdb' => 'msql_drop_db',
        'msql_fieldflags' => 'msql_field_flags',
        'msql_fieldlen' => 'msql_field_len',
        'msql_fieldname' => 'msql_field_name',
        'msql_fieldtable' => 'msql_field_table',
        'msql_fieldtype' => 'msql_field_type',
        'msql_freeresult' => 'msql_free_result',
        'msql_listdbs' => 'msql_list_dbs',
        'msql_listfields' => 'msql_list_fields',
        'msql_listtables' => 'msql_list_tables',
        'msql_numfields' => 'msql_num_fields',
        'msql_numrows' => 'msql_num_rows',
        'msql_regcase' => 'sql_regcase',
        'msql_selectdb' => 'msql_select_db',
        'msql_tablename' => 'msql_result',
        'mssql_affected_rows' => 'sybase_affected_rows',
        'mssql_close' => 'sybase_close',
        'mssql_connect' => 'sybase_connect',
        'mssql_data_seek' => 'sybase_data_seek',
        'mssql_fetch_array' => 'sybase_fetch_array',
        'mssql_fetch_field' => 'sybase_fetch_field',
        'mssql_fetch_object' => 'sybase_fetch_object',
        'mssql_fetch_row' => 'sybase_fetch_row',
        'mssql_field_seek' => 'sybase_field_seek',
        'mssql_free_result' => 'sybase_free_result',
        'mssql_get_last_message' => 'sybase_get_last_message',
        'mssql_min_client_severity' => 'sybase_min_client_severity',
        'mssql_min_error_severity' => 'sybase_min_error_severity',
        'mssql_min_message_severity' => 'sybase_min_message_severity',
        'mssql_min_server_severity' => 'sybase_min_server_severity',
        'mssql_num_fields' => 'sybase_num_fields',
        'mssql_num_rows' => 'sybase_num_rows',
        'mssql_pconnect' => 'sybase_pconnect',
        'mssql_query' => 'sybase_query',
        'mssql_result' => 'sybase_result',
        'mssql_select_db' => 'sybase_select_db',
        'multcolor' => 'swfdisplayitem_multColor',
        'mysql' => 'mysql_db_query',
        'mysql_createdb' => 'mysql_create_db',
        'mysql_db_name' => 'mysql_result',
        'mysql_dbname' => 'mysql_result',
        'mysql_dropdb' => 'mysql_drop_db',
        'mysql_fieldflags' => 'mysql_field_flags',
        'mysql_fieldlen' => 'mysql_field_len',
        'mysql_fieldname' => 'mysql_field_name',
        'mysql_fieldtable' => 'mysql_field_table',
        'mysql_fieldtype' => 'mysql_field_type',
        'mysql_freeresult' => 'mysql_free_result',
        'mysql_listdbs' => 'mysql_list_dbs',
        'mysql_listfields' => 'mysql_list_fields',
        'mysql_listtables' => 'mysql_list_tables',
        'mysql_numfields' => 'mysql_num_fields',
        'mysql_numrows' => 'mysql_num_rows',
        'mysql_selectdb' => 'mysql_select_db',
        'mysql_tablename' => 'mysql_result',
        'nextframe' => 'swfmovie_nextFrame and others',
        //'nextframe' => 'swfsprite_nextFrame',
        'ociassignelem' => 'OCI-Collection::assignElem',
        'ocibindbyname' => 'oci_bind_by_name',
        'ocicancel' => 'oci_cancel',
        'ocicloselob' => 'OCI-Lob::close',
        'ocicollappend' => 'OCI-Collection::append',
        'ocicollassign' => 'OCI-Collection::assign',
        'ocicollmax' => 'OCI-Collection::max',
        'ocicollsize' => 'OCI-Collection::size',
        'ocicolltrim' => 'OCI-Collection::trim',
        'ocicolumnisnull' => 'oci_field_is_null',
        'ocicolumnname' => 'oci_field_name',
        'ocicolumnprecision' => 'oci_field_precision',
        'ocicolumnscale' => 'oci_field_scale',
        'ocicolumnsize' => 'oci_field_size',
        'ocicolumntype' => 'oci_field_type',
        'ocicolumntyperaw' => 'oci_field_type_raw',
        'ocicommit' => 'oci_commit',
        'ocidefinebyname' => 'oci_define_by_name',
        'ocierror' => 'oci_error',
        'ociexecute' => 'oci_execute',
        'ocifetch' => 'oci_fetch',
        'ocifetchinto' => 'oci_fetch_array,',
        'ocifetchstatement' => 'oci_fetch_all',
        'ocifreecollection' => 'OCI-Collection::free',
        'ocifreecursor' => 'oci_free_statement',
        'ocifreedesc' => 'oci_free_descriptor',
        'ocifreestatement' => 'oci_free_statement',
        'ocigetelem' => 'OCI-Collection::getElem',
        'ociinternaldebug' => 'oci_internal_debug',
        'ociloadlob' => 'OCI-Lob::load',
        'ocilogon' => 'oci_connect',
        'ocinewcollection' => 'oci_new_collection',
        'ocinewcursor' => 'oci_new_cursor',
        'ocinewdescriptor' => 'oci_new_descriptor',
        'ocinlogon' => 'oci_new_connect',
        'ocinumcols' => 'oci_num_fields',
        'ociparse' => 'oci_parse',
        'ocipasswordchange' => 'oci_password_change',
        'ociplogon' => 'oci_pconnect',
        'ociresult' => 'oci_result',
        'ocirollback' => 'oci_rollback',
        'ocisavelob' => 'OCI-Lob::save',
        'ocisavelobfile' => 'OCI-Lob::import',
        'ociserverversion' => 'oci_server_version',
        'ocisetprefetch' => 'oci_set_prefetch',
        'ocistatementtype' => 'oci_statement_type',
        'ociwritelobtofile' => 'OCI-Lob::export',
        'ociwritetemporarylob' => 'OCI-Lob::writeTemporary',
        'odbc_do' => 'odbc_exec',
        'odbc_field_precision' => 'odbc_field_len',
        'output' => 'swfmovie_output',
        'pdf_add_outline' => 'pdf_add_bookmark',
        'pg_clientencoding' => 'pg_client_encoding',
        'pg_setclientencoding' => 'pg_set_client_encoding',
        'pos' => 'current',
        'recode' => 'recode_string',
        'remove' => 'swfmovie_remove and others',
        // 'remove' => 'swfsprite_remove',
        'rotate' => 'swfdisplayitem_rotate',
        'rotateto' => 'swfdisplayitem_rotateTo and others',
        // 'rotateto' => 'swffill_rotateTo',
        'save' => 'swfmovie_save',
        'savetofile' => 'swfmovie_saveToFile',
        'scale' => 'swfdisplayitem_scale',
        'scaleto' => 'swfdisplayitem_scaleTo and others',
        // 'scaleto' => 'swffill_scaleTo',
        'setaction' => 'swfbutton_setAction',
        'setbackground' => 'swfmovie_setBackground',
        'setbounds' => 'swftextfield_setBounds',
        'setcolor' => 'swftext_setColor and others',
        // 'setcolor' => 'swftextfield_setColor',
        'setdepth' => 'swfdisplayitem_setDepth',
        'setdimension' => 'swfmovie_setDimension',
        'setdown' => 'swfbutton_setDown',
        'setfont' => 'swftext_setFont and others',
        // 'setfont' => 'swftextfield_setFont',
        'setframes' => 'swfmovie_setFrames and others',
        // 'setframes' => 'swfsprite_setFrames',
        'setheight' => 'swftext_setHeight and others',
        // 'setheight' => 'swftextfield_setHeight',
        'sethit' => 'swfbutton_setHit',
        'setindentation' => 'swftextfield_setIndentation',
        'setleftfill' => 'swfshape_setleftfill',
        'setleftmargin' => 'swftextfield_setLeftMargin',
        'setline' => 'swfshape_setline',
        'setlinespacing' => 'swftextfield_setLineSpacing',
        'setmargins' => 'swftextfield_setMargins',
        'setmatrix' => 'swfdisplayitem_setMatrix',
        'setname' => 'swfdisplayitem_setName and others',
        // 'setname' => 'swftextfield_setName',
        'setover' => 'swfbutton_setOver',
        'setrate' => 'swfmovie_setRate',
        'setratio' => 'swfdisplayitem_setRatio',
        'setrightfill' => 'swfshape_setrightfill',
        'setrightmargin' => 'swftextfield_setRightMargin',
        'setspacing' => 'swftext_setSpacing',
        'setup' => 'swfbutton_setUp',
        'show_source' => 'highlight_file',
        'sizeof' => 'count',
        'skewx' => 'swfdisplayitem_skewX',
        'skewxto' => 'swfdisplayitem_skewXTo',
        // 'skewxto' => 'swffill_skewXTo',
        'skewy' => 'swfdisplayitem_skewY and others',
        'skewyto' => 'swfdisplayitem_skewYTo and others',
        // 'skewyto' => 'swffill_skewYTo',
        'snmpwalkoid' => 'snmprealwalk',
        'strchr' => 'strstr',
        'streammp3' => 'swfmovie_streamMp3',
        'swfaction' => 'swfaction_init',
        'swfbitmap' => 'swfbitmap_init',
        'swfbutton' => 'swfbutton_init',
        'swffill' => 'swffill_init',
        'swffont' => 'swffont_init',
        'swfgradient' => 'swfgradient_init',
        'swfmorph' => 'swfmorph_init',
        'swfmovie' => 'swfmovie_init',
        'swfshape' => 'swfshape_init',
        'swfsprite' => 'swfsprite_init',
        'swftext' => 'swftext_init',
        'swftextfield' => 'swftextfield_init',
        'xptr_new_context' => 'xpath_new_context',
        // miscellaneous
        'bzclose' => 'fclose',
        'bzflush' => 'fflush',
        'bzwrite' => 'fwrite',
        'dns_check_record' => 'checkdnsrr',
        'dir' => 'getdir',
        'ftp_quit' => 'ftp_close',
        'dns_get_mx' => 'getmxrr',
        // 'getrandmax' => 'mt_getrandmax',  // confusing because rand is not an alias of mt_rand
        'get_required_files' => 'get_included_files',
        'gmp_div' => 'gmp_div_q',
        // This may change in the future
        // 'gzclose' => 'fclose',
        // 'gzeof' => 'feof',
        // 'gzgetc' => 'fgetc',
        // 'gzgets' => 'fgets',
        // 'gzpassthru' => 'fpassthru',
        // 'gzread' => 'fread',
        // 'gzrewind' => 'rewind',
        // 'gzseek' => 'fseek',
        // 'gztell' => 'ftell',
        // 'gzwrite' => 'fwrite',
        'ldap_get_values' => 'ldap_get_values_len',
        'ldap_modify' => 'ldap_mod_replace',
        'mysqli_escape_string' => 'mysqli_real_escape_string',
        'mysqli_execute' => 'mysqli_stmt_execute',
        'mysqli_set_opt' => 'mysqli_options',
        'oci_free_cursor' => 'oci_free_statement',
        'openssl_get_privatekey' => 'openssl_pkey_get_private',
        'openssl_get_publickey' => 'openssl_pkey_get_public',
        'pcntl_errno' => 'pcntl_get_last_error',
        'pg_cmdtuples' => 'pg_affected_rows',
        'pg_errormessage' => 'pg_last_error',
        'pg_exec' => 'pg_query',
        'pg_fieldisnull' => 'pg_field_is_null',
        'pg_fieldname' => 'pg_field_name',
        'pg_fieldnum' => 'pg_field_num',
        'pg_fieldprtlen' => 'pg_field_prtlen',
        'pg_fieldsize' => 'pg_field_size',
        'pg_fieldtype' => 'pg_field_type',
        'pg_freeresult' => 'pg_free_result',
        'pg_getlastoid' => 'pg_last_oid',
        'pg_loclose' => 'pg_lo_close',
        'pg_locreate' => 'pg_lo_create',
        'pg_loexport' => 'pg_lo_export',
        'pg_loimport' => 'pg_lo_import',
        'pg_loopen' => 'pg_lo_open',
        'pg_loreadall' => 'pg_lo_read_all',
        'pg_loread' => 'pg_lo_read',
        'pg_lounlink' => 'pg_lo_unlink',
        'pg_lowrite' => 'pg_lo_write',
        'pg_numfields' => 'pg_num_fields',
        'pg_numrows' => 'pg_num_rows',
        'pg_result' => 'pg_fetch_result',
        'posix_errno' => 'posix_get_last_error',
        'session_commit' => 'session_write_close',
        'set_file_buffer' => 'stream_set_write_buffer',
        'snmp_set_oid_numeric_print' => 'snmp_set_oid_output_format',
        'socket_getopt' => 'socket_get_option',
        'socket_get_status' => 'stream_get_meta_data',
        'socket_set_blocking' => 'stream_set_blocking',
        'socket_setopt' => 'socket_set_option',
        'socket_set_timeout' => 'stream_set_timeout',
        'sodium_crypto_scalarmult_base' => 'sodium_crypto_box_publickey_from_secretkey',
        'srand' => 'mt_srand',
        'stream_register_wrapper' => 'stream_wrapper_register',
        'user_error' => 'trigger_error',
    ];

    public function beforeAnalyzePhase(CodeBase $code_base): void
    {
        foreach (self::KNOWN_ALIASES as $alias => $original_name) {
            try {
                $fqsen = FullyQualifiedFunctionName::fromFullyQualifiedString($alias);
            } catch (Exception $_) {
                continue;
            }
            if (!$code_base->hasFunctionWithFQSEN($fqsen)) {
                continue;
            }
            $function = $code_base->getFunctionByFQSEN($fqsen);
            if (!$function->isPHPInternal()) {
                continue;
            }
            $function->setIsDeprecated(true);
            if (!$function->getDocComment()) {
                $function->setDocComment('/** @deprecated DeprecateAliasPlugin marked this as an alias of ' .
                    $original_name . (strpos($original_name, ' ') === false ? '()' : '') .  '*/');
            }
        }
    }
}

if (Config::isIssueFixingPluginEnabled()) {
    require_once __DIR__ . '/DeprecateAliasPlugin/fixers.php';
}


// Every plugin needs to return an instance of itself at the
// end of the file in which it's defined.
return new DeprecateAliasPlugin();
