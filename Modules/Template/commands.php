<?php
/**
 * Command definitions of the CMS template language.
 *
 * This file is pure description - it contains no executable code, but
 * returns an array in which for each command it is stated what it is called, which parameters
 * it knows and which PHP call results from it. The parser (TemplateParserTrait) knows
 * not a single command; a new command means an entry here and a
 * matching method in Cms, Pagination resp. RecordUser.
 *
 * WHAT A COMMAND BECOMES
 * --------------------
 * From  (CMS:TEXT field=titel prefix="<h1>")  the parser creates, in essence,
 *
 *     <?php $_a = []; $_a["field"]="titel"; $_a["prefix"]="<h1>";
 *           $_r = $this->cms->cmsText($_a); ?>
 *
 * All parameters thus end up in the array $_a and pass as the only argument to the
 * method named in 'method'.
 *
 * KEYS OF A COMMAND DEFINITION
 * --------------------
 *     method       target method as 'class.method'; 'cms.' means $this->cms.
 *                  If the dot is missing, 'cms' is assumed. Available are
 *                  cms, db (always there) as well as pagination, recordUser and email -
 *                  the three are only loaded on first access, so a
 *                  template without the matching command never touches them.
 *                  See $lazyServices in Camindo\Core\Page.
 *     do           name of the parameter under which a value WITHOUT key is stored.
 *                  This allows writing (CMS:TEXT titel) instead of
 *                  (CMS:TEXT field=titel). At most one per command.
 *     oneof        comma list: at least one of these parameters must be given.
 *                  For commands that know several equivalent sources - CMS:TEXT
 *                  for example needs 'field', 'useval' or 'id'.
 *     params       the allowed parameters (see below)
 *     no_action    true: NO method call is generated. Only for commands whose
 *                  effect lies solely in the PHP snippets -
 *                  CMS:RESULT_START and CMS:RESULT_END are loop brackets.
 *     php_prefix   name(s) of files from Modules/Template/php/, whose content is inserted BEFORE
 *     php_suffix   resp. AFTER the method call. Available are
 *                  push_result, pop_result, loop_start, loop_end, include,
 *                  include_cached (only CMS:INCLUDE - closes the output cache with
 *                  Cms::includeEnd()), include_and_stop, select_count (only
 *                  CMS:SELECT - takes over the total count with hits=false,
 *                  Cms::selectCountEnd()), savedata_local (only CMS:SAVEDATA - binds
 *                  $GLOBALS[phpvar] as local variable of the template).
 *     buffer_set   true: the parameter 'set' captures the ENTIRE output of the command
 *                  including php_suffix (included template, result loop) and
 *                  stores it in $set[...] - Cms::setBufferStart()/setBufferEnd()
 *                  (CMS:SELECT, CMS:SELECT_LANGVERSION, CMS:INCLUDE).
 *     note         short description for the command reference (not read by the parser)
 *
 * KEYS OF A PARAMETER
 * --------------------
 *     mandatory    required? The parser reports a missing one as an error.
 *     subparam     allowed depth of sub-keys: 0 = none (only key=value),
 *                  1 = key['a']=value, 2 = key['a']['b']=value. Empty brackets (key[]=…)
 *                  do not count as a level, they only force appending to an array.
 *                  This is needed mainly by CMS:SELECT (filter, sort, …).
 *     default      default value - for documentation; it is not applied here,
 *                  but in the called method.
 *     operator     true: between key and value a comparison operator may stand instead of '='
 *                  (filter[preis] >= 100); the parser puts it in front of the value
 *                  (TemplateParserTrait::operatorPrefix()). For all other
 *                  parameters it reports an operator as an error. Set for 'filter'
 *                  (CMS:SELECT, CMS:ZIP, CMS:USER_AUTH, CMS:USER_PROTECT).
 *     php_value    true: an unquoted value that consists only of a variable
 *                  ($params, $cms['id'], {$set['x']}), is passed unchanged as a PHP expression
 *                  instead of being interpolated into a string - arrays and numbers
 *                  arrive type-correct this way (CMS:INCLUDE cacheparams).
 *     ref          the value refers to another record - basis for
 *                  TemplateParserTrait::mapReferences() (export/import of
 *                  template sets): 'template' (name or id), 'area' (name or
 *                  id), 'record' (record id; a non-numeric value is a slug
 *                  and remains). For parameters with sub-keys an array
 *                  first sub-key => type, e.g. filter: ['area' => 'area',
 *                  'area_id' => 'area', 'record_id' => 'record', 'id' => 'record'].
 *     list         true (only with ref 'template'): the value names a list template. A link
 *                  to a template that does not exist yet creates it prefilled with
 *                  CMS:RESULT_START/CMS:RESULT_END (TemplateBackend::info()).
 *     value        kind of expected value, for the command reference
 *     description  description, for the command reference
 *     extra_*      control entries for the command reference
 *
 * The parser actually evaluates only method, do, oneof, params,
 * params[*].mandatory, params[*].subparam, params[*].operator, params[*].php_value,
 * no_action, php_prefix, php_suffix and buffer_set.
 * Everything else - note, value, description, default, extra_*, include - is
 * documentation: note, description, value and default appear in the command picker of the
 * template input mask (TemplateBackend::command(), as tooltip resp. placeholder); 'include' is
 * an empty array in all entries.
 *
 * TEXTS ARE SNIPPETS
 * --------------------
 * note, description, value and default go through Template::commandText(): a value of the
 * form '#prefix.key' is a snippet, anything else is output as it is. The core texts live in
 * Modules/Template/backend/lang/{lang}.ini (section "Command reference"), plain text
 * without HTML entities:
 *
 *     note          #template.cmd_{command}                 one per command
 *     description   #template.cmd_{command}__{parameter}    one per parameter
 *     value         #template.cmdval_{hint}                 shared vocabulary ("Text",
 *                                                           "Record ID or slug", …)
 *     default       #template.cmddef_{hint}                 only for defaults that describe
 *                                                           ("(current record)")
 *
 * Value hints that are pure syntax stay literal - "true / false", "asc / desc",
 * "off / report / enforce", the operator list: the VS Code extension offers values
 * separated by " / " as choices (vscode-camindo/extension.js, choices()). Real default
 * values ('false', 'data', HTML templates of CMS:JUMP_*) stay literal as well. Commands of
 * add-on modules (settings.php['commands']) may use plain text or their own snippets.
 *
 * WIEDERKEHRENDE PARAMETER
 * --------------------
 * Many output commands share the same additional parameters:
 *
 *     ifempty   replacement text if the source is empty
 *     instead   text INSTEAD of the source if the source is not empty
 *     prefix    prepended, but only for a non-empty value
 *     suffix    likewise, appended
 *     set       do not output the result, but store it in $set[...]
 *     replace   replace partial texts ("search?replace" or replace[search]=replace);
 *               TEXT, DATE, DATETIME, TIME, NUMBER - for the format commands on the
 *               finished formatted value
 *     id        record the command refers to - if not given, the current one. The value is the same
 *               as can also appear in the URL:
 *               a pure sequence of digits is a record_id, anything else a slug
 *               (the successor of the C8 'idname'). Evaluated in Cms::_record().
 *               For CMS:SELECT this runs via 'filter' instead.
 *
 * The pattern "prefix/suffix only for a non-empty value" saves a case distinction
 * in the template: an <h1> is not output if it would remain empty.
 *
 * THE COMMANDS (alphabetical, in the order listed here)
 * --------------------
 *     Output         text, raw, number, date, datetime, time, media, url, email, xml,
 *                    message
 *     Links          relation
 *     Queries        select, select_langversion, result_start, result_end
 *     Navigation     jump_list, jump_next, jump_prev
 *     Control        include, continue, header, savedata
 *     Frontend-Edit  fed, fed_code
 *     Community      user_auth, user_basket, user_delete, user_input, user_login,
 *                    user_logout, user_protect, user_pw, user_register,
 *                    user_register_confirm
 *
 * The CMS:USER_* commands run in Camindo\Core\Modules\Record\RecordUser
 * (sessions: Camindo\Core\UserSession) - semantics, permissions and messages
 * in developer docs, chapter 12 (Community).
 *
 * @see Camindo\Core\Modules\Template\TemplateParserTrait  evaluates this file
 * @see Camindo\Core\Cms                                     the cms* methods
 * @see Camindo\Core\Pagination                              the CMS:JUMP_* methods
 * @see Camindo\Core\Modules\Record\RecordUser              the CMS:USER_* methods
 * @see developer docs, chapter 06 (Template compiler)       syntax and compiler
 * @see manual for template programmers                     command reference (one page per command)
 */

return [

    // Continues processing after a command with its own success template
    // (e.g. from the user_* group) has taken effect - without CONTINUE the template ends
    // an dieser Stelle.
    'continue' => [
        'method' => 'cms.cmsContinue',
        'note' => '#template.cmd_continue',
        'do' => 'field',
        'oneof' => '',
        'params' => [],
        'include' => []
    ],

    // DATE, DATETIME and TIME are the same command with a different excerpt:
    // DATE shows only the date part, TIME only the time, DATETIME both. The source
    // is in all three cases a date/time value from the database (ISO format).
    'date' => [
        'method' => 'cms.cmsDate',
        'note' => '#template.cmd_date',
        'do' => 'field',
        'oneof' => 'field,useval',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_current_record',
                'value' => '#template.cmdval_record_id_or_slug',
                'description' => '#template.cmd_date__id'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_field',
                'description' => '#template.cmd_date__field'
            ],
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_date_value',
                'description' => '#template.cmd_date__useval'
            ],
            'format' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_format_spec',
                'description' => '#template.cmd_date__format'
            ],
            'timezone' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_date__timezone'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_search_replace',
                'description' => '#template.cmd_date__replace'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_date__ifempty'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_date__instead'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_date__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_date__suffix'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_date__set'
            ]
        ],
        'include' => []
    ],

    'datetime' => [
        'method' => 'cms.cmsDatetime',
        'note' => '#template.cmd_datetime',
        'do' => 'field',
        'oneof' => 'field,useval',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_current_record',
                'value' => '#template.cmdval_record_id_or_slug',
                'description' => '#template.cmd_datetime__id'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_field',
                'description' => '#template.cmd_datetime__field'
            ],
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_date_value',
                'description' => '#template.cmd_datetime__useval'
            ],
            'format' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_format_spec',
                'description' => '#template.cmd_datetime__format'
            ],
            'autotime' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_datetime__autotime'
            ],
            'timezone' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_datetime__timezone'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_search_replace',
                'description' => '#template.cmd_datetime__replace'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_datetime__ifempty'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_datetime__instead'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_datetime__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_datetime__suffix'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_datetime__set'
            ]
        ],
        'include' => []
    ],

    'email' => [
        'method' => 'email.cmsEmail',
        'php_suffix' => 'include_and_stop',
        'note' => '#template.cmd_email',
        'do' => 'do',
        'oneof' => 'subject,subjecttemplate,body,bodytemplate',
        'params' => [
            'formguard' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => '#template.cmdval_true_false_seconds',
                'description' => '#template.cmd_email__formguard'
            ],
            'throttle' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_true_bucket',
                'description' => '#template.cmd_email__throttle'
            ],
            'to' => [
                'mandatory' => true,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_email',
                'description' => '#template.cmd_email__to'
            ],
            'toname' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_email__toname'
            ],
            'from' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_email_sender',
                'value' => '#template.cmdval_email',
                'description' => '#template.cmd_email__from'
            ],
            'fromname' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_email__fromname'
            ],
            'contenttype' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'text/plain',
                'value' => '#template.cmdval_content_type',
                'description' => '#template.cmd_email__contenttype'
            ],
            'subject' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '(no subject)',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_email__subject'
            ],
            'subjecttemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_template_name',
                'description' => '#template.cmd_email__subjecttemplate'
            ],
            'body' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_email__body'
            ],
            'bodytemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_template_name',
                'description' => '#template.cmd_email__bodytemplate'
            ],
            'paths_absolute' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_email__paths_absolute'
            ],
            'sender_url' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_instance_domain',
                'value' => '#template.cmdval_domain',
                'description' => '#template.cmd_email__sender_url',
                'extra_1' => 0,
                'extra_2' => ''
            ],
            'debug' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_email__debug'
            ],
            'requiredfields' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_field_names_comma',
                'description' => '#template.cmd_email__requiredfields'
            ],
            'data' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'data',
                'value' => '#template.cmdval_php_variable',
                'description' => '#template.cmd_email__data'
            ],
            'submit' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text_value',
                'description' => '#template.cmd_email__submit'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => '#template.cmd_email__successtemplate'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_email__message'
            ],
            'attachment' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '',
                'description' => '#template.cmd_email__attachment'
            ]
        ],
        'include' => []
    ],

    // FED, FED_SCRIPT and FED_CODE belong to frontend editing: they output something only when
    // the viewer has enabled it in their personal settings
    // (cookie 'fed', see Cms::fedActive()). FED creates the
    // edit button, FED_SCRIPT the associated JavaScript function cFED()
    // (once per page, usually in the footer), FED_CODE any HTML (for example
    // a toolbar).
    'fed' => [
        'method' => 'cms.cmsFed',
        'note' => '#template.cmd_fed',
        'do' => 'id',
        'oneof' => '',
        'params' => [
            'label' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '[»]',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_fed__label'
            ],
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_current_record',
                'value' => '#template.cmdval_record_id_or_slug',
                'description' => '#template.cmd_fed__id'
            ],
            'info' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_fed__info'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_fed__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_fed__suffix'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_fed__set'
            ]
        ],
        'include' => []
    ],

    'fed_script' => [
        'method' => 'cms.cmsFedScript',
        'note' => '#template.cmd_fed_script',
        'do' => 'set',
        'oneof' => '',
        'params' => [
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_fed_script__set'
            ]
        ],
        'include' => []
    ],

    'fed_code' => [
        'method' => 'cms.cmsFedCode',
        'note' => '#template.cmd_fed_code',
        'do' => 'code',
        'oneof' => '',
        'params' => [
            'code' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_html_code',
                'description' => '#template.cmd_fed_code__code'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_html_code',
                'description' => '#template.cmd_fed_code__instead'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_fed_code__set'
            ]
        ],
        'include' => []
    ],

    'header' => [
        'method' => 'cms.cmsHeader',
        'note' => '#template.cmd_header',
        'do' => 'text',
        'oneof' => '',
        'params' => [
            'text' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_header__text'
            ],
            'overwrite' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_header__overwrite'
            ]
        ],
        'include' => []
    ],

    'csp' => [
        'method' => 'cms.cmsCsp',
        'note' => '#template.cmd_csp',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'script' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_sources',
                'description' => '#template.cmd_csp__script'
            ],
            'form' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_sources',
                'description' => '#template.cmd_csp__form'
            ],
            'mode' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'off / report / enforce',
                'description' => '#template.cmd_csp__mode'
            ],
        ],
        'include' => []
    ],

    'include' => [
        'method' => 'cms.cmsInclude',
        'buffer_set' => true,
        'php_suffix' => 'include_cached',
        'note' => '#template.cmd_include',
        'do' => 'template',
        'oneof' => '',
        'params' => [
            'caching' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_duration',
                'description' => '#template.cmd_include__caching'
            ],
            'cacheparams' => [
                'mandatory' => false,
                'subparam' => 0,
                'php_value' => true,
                'default' => '',
                'value' => '#template.cmdval_cacheparams',
                'description' => '#template.cmd_include__cacheparams'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_include__set'
            ],
            'template' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_template_name',
                'description' => '#template.cmd_include__template'
            ]
        ],
        'include' => []
    ],

    'jump_list' => [
        'method' => 'pagination.cmsJumpList',
        'note' => '#template.cmd_jump_list',
        'do' => 'separator',
        'oneof' => '',
        'params' => [
            'skip' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '$_REQUEST[skip]',
                'value' => '#template.cmdval_number_min0',
                'description' => '#template.cmd_jump_list__skip'
            ],
            'maximum' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '7',
                'value' => '#template.cmdval_number_min1',
                'description' => '#template.cmd_jump_list__maximum'
            ],
            'boundaries' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_jump_list__boundaries'
            ],
            'separator' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' ',
                'value' => '#template.cmdval_separator',
                'description' => '#template.cmd_jump_list__separator',
                'extra_1' => 'textsmall',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'tpl_link' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' <a href="{href}">{page}</a> ',
                'value' => '#template.cmdval_html_template',
                'description' => '#template.cmd_jump_list__tpl_link'
            ],
            'tpl_active' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' <strong>{page}</strong> ',
                'value' => '#template.cmdval_html_template',
                'description' => '#template.cmd_jump_list__tpl_active'
            ],
            'ellipsis' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' … ',
                'value' => '#template.cmdval_code',
                'description' => '#template.cmd_jump_list__ellipsis'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_search_replace',
                'description' => '#template.cmd_jump_list__replace'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_jump_list__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_jump_list__suffix'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_jump_list__ifempty'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_jump_list__set'
            ]
        ],
        'include' => []
    ],

    'jump_next' => [
        'method' => 'pagination.cmsJumpNext',
        'note' => '#template.cmd_jump_next',
        'do' => 'tpl_link',
        'oneof' => '',
        'params' => [
            'skip' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '$_REQUEST[skip]',
                'value' => '#template.cmdval_number_min0',
                'description' => '#template.cmd_jump_next__skip'
            ],
            'tpl_link' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' <a href="{href}"><strong>❯❯</strong></a> ',
                'value' => '#template.cmdval_html_template',
                'description' => '#template.cmd_jump_next__tpl_link'
            ],
            'tpl_inactive' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' <span>❯❯</span> ',
                'value' => '#template.cmdval_html_template',
                'description' => '#template.cmd_jump_next__tpl_inactive'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_search_replace',
                'description' => '#template.cmd_jump_next__replace'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_jump_next__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_jump_next__suffix'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_jump_next__ifempty'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_jump_next__set'
            ]
        ],
        'include' => []
    ],

    'jump_prev' => [
        'method' => 'pagination.cmsJumpPrev',
        'note' => '#template.cmd_jump_prev',
        'do' => 'tpl_link',
        'oneof' => '',
        'params' => [
            'skip' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '$_REQUEST[skip]',
                'value' => '#template.cmdval_number_min0',
                'description' => '#template.cmd_jump_prev__skip'
            ],
            'tpl_link' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' <a href="{href}"><strong>❮❮</strong></a> ',
                'value' => '#template.cmdval_html_template',
                'description' => '#template.cmd_jump_prev__tpl_link'
            ],
            'tpl_inactive' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => ' <span>❮❮</span> ',
                'value' => '#template.cmdval_html_template',
                'description' => '#template.cmd_jump_prev__tpl_inactive'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_search_replace',
                'description' => '#template.cmd_jump_prev__replace'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_jump_prev__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_jump_prev__suffix'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_jump_prev__ifempty'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_jump_prev__set'
            ]
        ],
        'include' => []
    ],

    'media' => [
        'method' => 'cms.cmsMedia',
        'note' => '#template.cmd_media',
        'do' => 'field',
        'oneof' => 'field,useval',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_current_record',
                'value' => '#template.cmdval_record_id_or_slug',
                'description' => '#template.cmd_media__id'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => '#template.cmd_media__field'
            ],
            'subfield' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => 'path / file / filename / mime_type / extension / size / width / height / version',
                'description' => '#template.cmd_media__subfield'
            ],
            'auth' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_media__auth'
            ],
            'download' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_media__download'
            ],
            'width' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_media__width'
            ],
            'height' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_media__height'
            ],
            'attr' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_array',
                'description' => '#template.cmd_media__attr'
            ],
            'autoformat' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_media__autoformat',
                'extra_1' => 'text',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_media__instead'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_media__ifempty'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_media__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_media__suffix'
            ],
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_array',
                'description' => '#template.cmd_media__useval'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_media__set'
            ]
        ],
        'include' => []
    ],

    'message' => [
        'method' => 'cms.cmsMessage',
        'note' => '#template.cmd_message',
        'do' => 'useval',
        'oneof' => '',
        'params' => [
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_message__useval'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_message__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_message__suffix'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_message__ifempty'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_message__instead'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_message__set'
            ]
        ],
        'include' => []
    ],

    'nonce' => [
        'method' => 'cms.cmsNonce',
        'note' => '#template.cmd_nonce',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'format' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '"attribute"',
                'value' => '"attribute" / "value"',
                'description' => '#template.cmd_nonce__format'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_nonce__set'
            ],
        ],
        'include' => []
    ],

    'number' => [
        'method' => 'cms.cmsNumber',
        'note' => '#template.cmd_number',
        'do' => 'field',
        'oneof' => 'field,useval',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_current_record',
                'value' => '#template.cmdval_record_id_or_slug',
                'description' => '#template.cmd_number__id'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_field',
                'description' => '#template.cmd_number__field'
            ],
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_number',
                'description' => '#template.cmd_number__useval'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_number__ifempty'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_number',
                'description' => '#template.cmd_number__instead'
            ],
            'mul' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '1',
                'value' => '#template.cmdval_number',
                'description' => '#template.cmd_number__mul'
            ],
            'add' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '0',
                'value' => '#template.cmdval_number',
                'description' => '#template.cmd_number__add'
            ],
            'format' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_formatting',
                'description' => '#template.cmd_number__format'
            ],
            'decimals' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_number_0_10',
                'description' => '#template.cmd_number__decimals'
            ],
            'decimal' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '.',
                'value' => '#template.cmdval_character',
                'description' => '#template.cmd_number__decimal',
                'extra_1' => 'textsmall',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'thousands_separator' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_character',
                'description' => '#template.cmd_number__thousands_separator',
                'extra_1' => 'textsmall',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'nullcent' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_number__nullcent'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_search_replace',
                'description' => '#template.cmd_number__replace'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_number__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_number__suffix'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_number__set'
            ],
            'sizefactor' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_kilobyte',
                'description' => '#template.cmd_number__sizefactor'
            ]
        ],
        'include' => []
    ],

    // RAW outputs the field content without any processing - no escaping, no
    // line break conversion. Intended for fields that already contain HTML; accordingly
    // the content must be trusted.
    'raw' => [
        'method' => 'cms.cmsRaw',
        'note' => '#template.cmd_raw',
        'do' => 'field',
        'oneof' => '',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_current_record',
                'value' => '#template.cmdval_record_id_or_slug',
                'description' => '#template.cmd_raw__id'
            ],
            'field' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_field',
                'description' => '#template.cmd_raw__field'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_raw__ifempty'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_raw__instead'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_raw__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_raw__suffix'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_raw__set'
            ]
        ],
        'include' => []
    ],

    'relation' => [
        'method' => 'cms.cmsRelation',
        'note' => '#template.cmd_relation',
        'do' => 'field',
        'oneof' => '',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_current_record',
                'value' => '#template.cmdval_record_id_or_slug',
                'description' => '#template.cmd_relation__id'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_field',
                'description' => '#template.cmd_relation__field'
            ],
            'limit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '1000',
                'value' => '#template.cmdval_number_positive',
                'description' => '#template.cmd_relation__limit'
            ],
            'future' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_relation__future'
            ],
            'archive' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_relation__archive'
            ],
            'state' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '0',
                'value' => '#template.cmdval_status_or_any',
                'description' => '#template.cmd_relation__state'
            ],
            'lang' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_current_language',
                'value' => '#template.cmdval_language_code',
                'description' => '#template.cmd_relation__lang',
                'extra_1' => 'textsmall',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'separator' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '|',
                'value' => '#template.cmdval_separator',
                'description' => '#template.cmd_relation__separator',
                'extra_1' => 'textsmall',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_relation__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_relation__suffix'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_relation__ifempty'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_relation__instead'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_relation__set'
            ]
            // 'id' is still missing here => 
        ],
        'include' => []
    ],
    
    // RESULT_START / RESULT_END are brackets, not commands in the strict sense:
    // no_action prevents the method call, the effect lies solely in the
    // snippets php/loop_start.php and php/loop_end.php, which open and close the foreach loop
    // over the hits.
    'result_start' => [
        'method' => 'cms.cmsResultStart',
        'no_action' => true,
        'php_suffix' => 'loop_start',
        'note' => '#template.cmd_result_start',
        'do' => 'do',
        'oneof' => '',
        'params' => [],
        'include' => []
    ],

    'result_end' => [
        'method' => 'cms.cmsResultEnd',
        'no_action' => true,
        'php_suffix' => 'loop_end',
        'note' => '#template.cmd_result_end',
        'do' => 'do',
        'oneof' => '',
        'params' => [],
        'include' => []
    ],

    'savedata' => [
        'method' => 'cms.cmsSaveData',
        'php_suffix' => 'savedata_local',
        'note' => '#template.cmd_savedata',
        'do' => 'phpvar',
        'oneof' => '',
        'params' => [
            'phpvar' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_globals_index',
                'description' => '#template.cmd_savedata__phpvar'
            ],
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_record_id_or_slug',
                'description' => '#template.cmd_savedata__id',
                'extra_1' => 'text',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'lang' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '$lang',
                'value' => '#template.cmdval_language_version_code',
                'description' => '#template.cmd_savedata__lang',
                'extra_1' => 'userlang',
                'extra_2' => 1,
                'extra_3' => ''
            ]
        ],
        'include' => []
    ],

    // SELECT is the most complex command: a query whose hits are passed to a second
    // template ('resulttemplate').
    //
    // The three snippets around it explain the flow:
    //     push_result   set the running query result aside - a SELECT can
    //                   stand inside a result loop and would otherwise overwrite it
    //     include       include the resulttemplate that outputs the hits
    //     pop_result    fetch the outer result back, the outer loop runs
    //                   on
    //     select_count  only with hits=false: take over the counted total into the calling
    //                   template (there is no resulttemplate in which it would otherwise stand)
    //
    // The filter specifications need sub-keys (filter['area_id'], sort['name']),
    // which is why their subparam is 1 or 2.
    'select' => [
        'method' => 'cms.cmsSelect',
        'buffer_set' => true,
        'php_prefix' => 'push_result',
        'php_suffix' => ['include', 'pop_result', 'select_count'],
        'note' => '#template.cmd_select',
        'do' => 'resulttemplate',
        'oneof' => 'filter,relation',
        'params' => [
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_select__set'
            ],
            'resulttemplate' => [
                'ref' => 'template',
                'list' => true,
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_template_name',
                'description' => '#template.cmd_select__resulttemplate'
            ],
            'noresulttemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_template_name',
                'description' => '#template.cmd_select__noresulttemplate'
            ],
            'noresult' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_select__noresult'
            ],
            'filter' => [
                'ref' => ['area' => 'area', 'area_id' => 'area', 'record_id' => 'record', 'id' => 'record'],
                'operator' => true,
                'mandatory' => false,
                'subparam' => 2,
                'default' => '',
                'value' => '#template.cmdval_value_or_empty',
                'description' => '#template.cmd_select__filter'
            ],
            'filter_mode' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => 'OR',
                'value' => 'AND / OR',
                'description' => '#template.cmd_select__filter_mode'
            ],
            'filter_op' => [
                'mandatory' => false,
                'subparam' => 2,
                'default' => '=',
                'value' => '=, ==, !=, !!, >, <, >>, <<, >=, <=',
                'description' => '#template.cmd_select__filter_op',
            ],
            'skip' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '0',
                'value' => '#template.cmdval_number_min0',
                'description' => '#template.cmd_select__skip'
            ],
            'limit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_number_positive',
                'description' => '#template.cmd_select__limit'
            ],
            'sort' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => 'changed_at',
                'value' => '#template.cmdval_field_names',
                'description' => '#template.cmd_select__sort'
            ],
            'order' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => 'asc',
                'value' => 'desc / asc',
                'description' => '#template.cmd_select__order'
            ],
            'lang' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_current_language',
                'value' => '#template.cmdval_language_code',
                'description' => '#template.cmd_select__lang',
                'extra_1' => 'textsmall',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'relation' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_field_names_or_any',
                'description' => '#template.cmd_select__relation',
                'extra_1' => 'relationfield',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'src_id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_current_record',
                'value' => '#template.cmdval_record_ids',
                'description' => '#template.cmd_select__src_id',
                'extra_1' => 'ids',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'dest_id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_record_id',
                'description' => '#template.cmd_select__dest_id',
                'extra_1' => 'ids',
                'extra_2' => 1,
                'extra_3' => ''
            ],
            'nextof' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_record_id',
                'description' => '#template.cmd_select__nextof'
            ],
            'count' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_select__count'
            ],
            'hits' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_select__hits'
            ],
            'archive' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_select__archive'
            ],
            'future' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_select__future'
            ],
            'debug' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_select__debug'
            ],
            'sql' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => '#template.cmd_select__sql'
            ],
            'state' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '0',
                'value' => '#template.cmdval_status_or_any',
                'description' => '#template.cmd_select__state'
            ],
            'request' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_select_request',
                'description' => '#template.cmd_select__request'
            ]
        ],
        'include' => []
    ],

    'select_langversion' => [
        'method' => 'cms.cmsSelectLanguages',
        'buffer_set' => true,
        'php_prefix' => 'push_result',
        'php_suffix' => ['include', 'pop_result'],
        'note' => '#template.cmd_select_langversion',
        'do' => 'resulttemplate',
        'oneof' => '',
        'params' => [
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_select_langversion__set'
            ],
            'resulttemplate' => [
                'ref' => 'template',
                'list' => true,
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_template_name',
                'description' => '#template.cmd_select_langversion__resulttemplate'
            ]
        ],
        'include' => []
    ],

    'snippet' => [
        'method' => 'cms.cmsSnippet',
        'note' => '#template.cmd_snippet',
        'do' => 'key',
        'oneof' => '',
        'params' => [
            'key' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_snippet_key',
                'description' => '#template.cmd_snippet__key'
            ],
            'param' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_snippet__param'
            ],
            'specialchars' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_snippet__specialchars'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_snippet__ifempty'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_snippet__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_snippet__suffix'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_snippet__set'
            ]
        ],
        'include' => []
    ],

    'text' => [
        'method' => 'cms.cmsText',
        'note' => '#template.cmd_text',
        'do' => 'field',
        'oneof' => 'field,useval,id',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_record_id_or_slug',
                'description' => '#template.cmd_text__id'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_field',
                'description' => '#template.cmd_text__field'
            ],
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_text__useval'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_text__ifempty'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_text__instead'
            ],
            'entitydecode' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_text__entitydecode'
            ],
            'trim' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_text__trim'
            ],
            'striptags' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_text__striptags'
            ],
            'if' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_search_replace',
                'description' => '#template.cmd_text__if'
            ],
            'else' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_text__else'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_search_replace',
                'description' => '#template.cmd_text__replace'
            ],
            'limit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_number_min0',
                'description' => '#template.cmd_text__limit'
            ],
            'lbrconvert' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_text__lbrconvert'
            ],
            'specialchars' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_text__specialchars'
            ],
            'urlconvert' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_text__urlconvert'
            ],
            'emailconvert' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false / encrypt',
                'description' => '#template.cmd_text__emailconvert'
            ],
            'emaillimit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '>= 0',
                'description' => '#template.cmd_text__emaillimit'
            ],
            'emailtext' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_text__emailtext'
            ],
            'target' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '_self',
                'value' => '#template.cmdval_window',
                'description' => '#template.cmd_text__target'
            ],
            'markdown' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_text__markdown'
            ],
            'urlencode' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_text__urlencode'
            ],
            'urllimit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '80',
                'value' => '#template.cmdval_characters',
                'description' => '#template.cmd_text__urllimit'
            ],
            'ampconvert' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_text__ampconvert'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_text__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_text__suffix'
            ],
            'iso' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_text__iso'
            ],
            'charset' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'utf-8',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_text__charset'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_text__set'
            ]
        ],
        'include' => []
    ],

    'time' => [
        'method' => 'cms.cmsTime',
        'note' => '#template.cmd_time',
        'do' => 'field',
        'oneof' => 'field,useval',
        'params' => [
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_current_record',
                'value' => '#template.cmdval_record_id_or_slug',
                'description' => '#template.cmd_time__id'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_field',
                'description' => '#template.cmd_time__field'
            ],
            'useval' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_time_value',
                'description' => '#template.cmd_time__useval'
            ],
            'format' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_format_spec',
                'description' => '#template.cmd_time__format'
            ],
            'timezone' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_time__timezone'
            ],
            'replace' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_search_replace',
                'description' => '#template.cmd_time__replace'
            ],
            'ifempty' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_time__ifempty'
            ],
            'instead' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_time__instead'
            ],
            'prefix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_time__prefix'
            ],
            'suffix' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_time__suffix'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_time__set'
            ]
        ],
        'include' => []
    ],

    'url' => [
        'method' => 'cms.cmsUrl',
        'note' => '#template.cmd_url',
        'do' => 'id',
        'oneof' => '',
        'params' => [
            'area' => [
                'ref' => 'area',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_area',
                'description' => '#template.cmd_url__area'
            ],
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_current_record',
                'value' => '#template.cmdval_record_id_or_slug',
                'description' => '#template.cmd_url__id'
            ],
            'template' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_template_name',
                'description' => '#template.cmd_url__template'
            ],
            'lang' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_current_language',
                'value' => '#template.cmdval_language_code',
                'description' => '#template.cmd_url__lang'
            ],
            'protocol' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_instance_protocol',
                'value' => '#template.cmdval_protocol',
                'description' => '#template.cmd_url__protocol'
            ],
            'host' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_host',
                'description' => '#template.cmd_url__host'
            ],
            'absolute' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_url__absolute'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_url__set'
            ]
        ],
        'include' => []
    ],

    'xml' => [
        'method' => 'cms.cmsXml',
        'note' => '#template.cmd_xml',
        'do' => 'encoding',
        'oneof' => '',
        'params' => [
            'encoding' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'utf-8',
                'value' => 'encoding',
                'description' => '#template.cmd_xml__encoding'
            ],
            'version' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '1.0',
                'value' => 'version',
                'description' => '#template.cmd_xml__version'
            ]
        ],
        'include' => []
    ],

    // ---- Community Features -----

    // ==========================================================================
    // CMS:USER_* - Frontend-Community
    //
    // Login, registration, contributions, shopping basket and access protection for
    // Website-Besucher. Laufzeit: Camindo\Core\Modules\Record\RecordUser; Profile
    // lie in areas of type 'profile', contributions in areas of type 'ugc'.
    // Ergebnisse stehen in $set (user_id, user, user_session_id, hash, record_id,
    // basket ...), messages can be replaced per command via 'message[index]'.
    // See developer docs, chapter 12 (Community).
    // ==========================================================================

    'zip' => [
        'method' => 'zip.cmsZip',
        'note' => '#template.cmd_zip',
        'do' => 'field',
        'oneof' => 'filter,relation,ids,source',
        'params' => [
            'field' => [
                'mandatory' => true, 'subparam' => 0, 'default' => '',
                'value' => '#template.cmdval_field_names',
                'description' => '#template.cmd_zip__field'
            ],
            'filter' => [
                'ref' => ['area' => 'area', 'area_id' => 'area', 'record_id' => 'record'],
                'operator' => true,
                'mandatory' => false, 'subparam' => 2, 'default' => '', 'value' => '#template.cmdval_value',
                'description' => '#template.cmd_zip__filter'
            ],
            'filter_op' => ['mandatory' => false, 'subparam' => 2, 'default' => '=', 'value' => '#template.cmdval_operator', 'description' => '#template.cmd_zip__filter_op'],
            'filter_mode' => ['mandatory' => false, 'subparam' => 1, 'default' => 'OR', 'value' => 'AND / OR', 'description' => '#template.cmd_zip__filter_mode'],
            'relation' => ['mandatory' => false, 'subparam' => 0, 'default' => '', 'value' => '#template.cmdval_field_name', 'description' => '#template.cmd_zip__relation'],
            'src_id' => ['ref' => 'record', 'mandatory' => false, 'subparam' => 0, 'default' => '#template.cmddef_current_record', 'value' => '#template.cmdval_record_id', 'description' => '#template.cmd_zip__src_id'],
            'ids' => ['mandatory' => false, 'subparam' => 0, 'default' => '', 'value' => '#template.cmdval_record_ids_comma', 'description' => '#template.cmd_zip__ids'],
            'source' => ['mandatory' => false, 'subparam' => 0, 'default' => '', 'value' => 'basket', 'description' => '#template.cmd_zip__source'],
            'category' => ['mandatory' => false, 'subparam' => 0, 'default' => '0', 'value' => '#template.cmdval_number', 'description' => '#template.cmd_zip__category'],
            'sort' => ['mandatory' => false, 'subparam' => 1, 'default' => '', 'value' => '#template.cmdval_field_name', 'description' => '#template.cmd_zip__sort'],
            'order' => ['mandatory' => false, 'subparam' => 1, 'default' => 'asc', 'value' => 'asc / desc', 'description' => '#template.cmd_zip__order'],
            'name' => ['mandatory' => false, 'subparam' => 0, 'default' => 'download', 'value' => '#template.cmdval_text', 'description' => '#template.cmd_zip__name'],
            'filenames' => ['mandatory' => false, 'subparam' => 0, 'default' => '{slug}.{extension}', 'value' => '#template.cmdval_pattern', 'description' => '#template.cmd_zip__filenames'],
            'credits' => ['mandatory' => false, 'subparam' => 0, 'default' => '', 'value' => '#template.cmdval_field_names_comma', 'description' => '#template.cmd_zip__credits'],
            'maxfiles' => ['mandatory' => false, 'subparam' => 0, 'default' => '(user.zip.max_files)', 'value' => '#template.cmdval_number', 'description' => '#template.cmd_zip__maxfiles'],
            'maxsize' => ['mandatory' => false, 'subparam' => 0, 'default' => '(user.zip.max_mb)', 'value' => 'MB', 'description' => '#template.cmd_zip__maxsize'],
            'future' => ['mandatory' => false, 'subparam' => 0, 'default' => 'false', 'value' => 'true / false', 'description' => '#template.cmd_zip__future'],
            'archive' => ['mandatory' => false, 'subparam' => 0, 'default' => 'false', 'value' => 'true / false', 'description' => '#template.cmd_zip__archive'],
            'cookiename' => ['mandatory' => false, 'subparam' => 0, 'default' => 'usess', 'value' => '#template.cmdval_cookie', 'description' => '#template.cmd_zip__cookiename'],
            'message' => ['mandatory' => false, 'subparam' => 1, 'default' => '', 'value' => '#template.cmdval_text', 'description' => '#template.cmd_zip__message'],
        ],
        'include' => []
    ],

    'user_token' => [
        'method' => 'recordUser.cmsToken',
        'note' => '#template.cmd_user_token',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'formguard' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_user_token__formguard'
            ],
            'format' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '"field"',
                'value' => '"field" / "value"',
                'description' => '#template.cmd_user_token__format'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_user_token__set'
            ]
        ],
        'include' => []
    ],

    'user_password_rules' => [
        'method' => 'recordUser.cmsPasswordRules',
        'note' => '#template.cmd_user_password_rules',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'container' => [
                'ref' => 'area',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_container',
                'description' => '#template.cmd_user_password_rules__container'
            ],
            'field' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_login_password_field',
                'value' => '#template.cmdval_field_name',
                'description' => '#template.cmd_user_password_rules__field'
            ],
            'set' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_set_index',
                'description' => '#template.cmd_user_password_rules__set'
            ]
        ],
        'include' => []
    ],

    'user_auth' => [
        'method' => 'recordUser.cmsAuth',
        'note' => '#template.cmd_user_auth',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'sessionid' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_session_id',
                'description' => '#template.cmd_user_auth__sessionid'
            ],
            'filter' => [
                'ref' => ['area' => 'area', 'area_id' => 'area', 'record_id' => 'record', 'id' => 'record'],
                'operator' => true,
                'mandatory' => false,
                'subparam' => 2,
                'default' => '',
                'value' => '#template.cmdval_select_filter',
                'description' => '#template.cmd_user_auth__filter'
            ],
            'mode' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => 'OR',
                'value' => 'AND / OR',
                'description' => '#template.cmd_user_auth__mode'
            ],
            'cookiename' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'usess',
                'value' => '#template.cmdval_cookie',
                'description' => '#template.cmd_user_auth__cookiename'
            ]
        ],
        'include' => []
    ],

    'user_basket' => [
        'method' => 'recordUser.cmsBasket',
        'note' => '#template.cmd_user_basket',
        'do' => 'function',
        'oneof' => '',
        'params' => [
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_user_basket__csrf'
            ],
            'function' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '"load"',
                'value' => '"load" / "add" / "update" / "update_addon"',
                'description' => '#template.cmd_user_basket__function'
            ],
            'overwrite' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'false / true',
                'description' => '#template.cmd_user_basket__overwrite'
            ],
            'category' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '0',
                'value' => '#template.cmdval_category_id',
                'description' => '#template.cmd_user_basket__category'
            ],
            'container' => [
                'ref' => 'area',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_containers',
                'description' => '#template.cmd_user_basket__container'
            ],
            'variable' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_variable',
                'description' => '#template.cmd_user_basket__variable'
            ],
            'duration' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '30',
                'value' => '#template.cmdval_days',
                'description' => '#template.cmd_user_basket__duration'
            ],
            'basket_addon' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_user_basket__basket_addon'
            ]
        ],
        'include' => [
        ]
    ],

    'user_delete' => [
        'method' => 'recordUser.cmsDelete',
        'note' => '#template.cmd_user_delete',
        'do' => 'id',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_user_delete__csrf'
            ],
            'id' => [
                'ref' => 'record',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_record_id',
                'description' => '#template.cmd_user_delete__id'
            ],
            'ignoreuser' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_user_delete__ignoreuser'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_template_name',
                'description' => '#template.cmd_user_delete__successtemplate'
            ],
            'message_error' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '#template.cmddef_access_denied',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_user_delete__message_error'
            ]
        ],
        'include' => [
        ]
    ],

    'user_input' => [
        'method' => 'recordUser.cmsInput',
        'note' => '#template.cmd_user_input',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_user_input__csrf'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_template_name',
                'description' => '#template.cmd_user_input__successtemplate'
            ],
            'data' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'data',
                'value' => '#template.cmdval_php_variable',
                'description' => '#template.cmd_user_input__data'
            ],
            'overwrite' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_key_value_pairs',
                'description' => '#template.cmd_user_input__overwrite'
            ],
            'container' => [
                'ref' => 'area',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_container',
                'description' => '#template.cmd_user_input__container'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '#template.cmddef_default_text',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_user_input__message'
            ],
            'submit' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text_value',
                'description' => '#template.cmd_user_input__submit'
            ],
            'fields' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_comma_list',
                'description' => '#template.cmd_user_input__fields'
            ],
            'id' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_record_id',
                'description' => '#template.cmd_user_input__id'
            ],
            'ignoreuser' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_user_input__ignoreuser'
            ],
            'forceupdate' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_user_input__forceupdate'
            ],
            'masterid' => [
                'ref' => 'record',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_record_id',
                'description' => '#template.cmd_user_input__masterid'
            ],
            'owner' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_user_id',
                'description' => '#template.cmd_user_input__owner'
            ],
            'removefiles' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_comma_list',
                'description' => '#template.cmd_user_input__removefiles'
            ],
            'currentfield' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'current_password',
                'value' => '#template.cmdval_field_name',
                'description' => '#template.cmd_user_input__currentfield'
            ]
        ],
        'include' => [
        ]
    ],

    'user_login' => [
        'method' => 'recordUser.cmsLogin',
        'note' => '#template.cmd_user_login',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'codetemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_template_name',
                'description' => '#template.cmd_user_login__codetemplate'
            ],
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_user_login__csrf'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => '#template.cmd_user_login__successtemplate'
            ],
            'container' => [
                'ref' => 'area',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_container',
                'description' => '#template.cmd_user_login__container'
            ],
            'data' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'data',
                'value' => '#template.cmdval_php_variable',
                'description' => '#template.cmd_user_login__data'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_default_message',
                'description' => '#template.cmd_user_login__message'
            ],
            'hash' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_hash',
                'description' => '#template.cmd_user_login__hash'
            ],
            'trim' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_user_login__trim'
            ],
            'cookie' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_user_login__cookie'
            ],
            'submit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text_value',
                'description' => '#template.cmd_user_login__submit'
            ],
            'cookiename' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'usess',
                'value' => '#template.cmdval_cookie',
                'description' => '#template.cmd_user_login__cookiename'
            ]
        ],
        'include' => [
        ]
    ],

    'user_login_code' => [
        'method' => 'recordUser.cmsLoginCode',
        'note' => '#template.cmd_user_login_code',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'codetemplate' => [
                'ref' => 'template',
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_template_name',
                'description' => '#template.cmd_user_login_code__codetemplate'
            ],
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_user_login_code__csrf'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => '#template.cmd_user_login_code__successtemplate'
            ],
            'submit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text_value',
                'description' => '#template.cmd_user_login_code__submit'
            ],
            'resend' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => 'true / false',
                'description' => '#template.cmd_user_login_code__resend'
            ],
            'data' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'data',
                'value' => '#template.cmdval_php_variable',
                'description' => '#template.cmd_user_login_code__data'
            ],
            'codefield' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'code',
                'value' => '#template.cmdval_field_name',
                'description' => '#template.cmd_user_login_code__codefield'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_default_message',
                'description' => '#template.cmd_user_login_code__message'
            ],
            'sessionid' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_session_id',
                'description' => '#template.cmd_user_login_code__sessionid'
            ],
            'cookiename' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'usess',
                'value' => '#template.cmdval_cookie',
                'description' => '#template.cmd_user_login_code__cookiename'
            ],
        ],
        'include' => []
    ],

    'user_2fa' => [
        'method' => 'recordUser.cmsTwoFactor',
        'note' => '#template.cmd_user_2fa',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_user_2fa__csrf'
            ],
            'function' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'status',
                'value' => 'status / totp_setup / totp_enable / code_enable / disable / recovery_renew',
                'description' => '#template.cmd_user_2fa__function'
            ],
            'submit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text_value',
                'description' => '#template.cmd_user_2fa__submit'
            ],
            'data' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'data',
                'value' => '#template.cmdval_php_variable',
                'description' => '#template.cmd_user_2fa__data'
            ],
            'codefield' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'code',
                'value' => '#template.cmdval_field_name',
                'description' => '#template.cmd_user_2fa__codefield'
            ],
            'currentfield' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'current_password',
                'value' => '#template.cmdval_field_name',
                'description' => '#template.cmd_user_2fa__currentfield'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_default_message',
                'description' => '#template.cmd_user_2fa__message'
            ],
            'cookiename' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'usess',
                'value' => '#template.cmdval_cookie',
                'description' => '#template.cmd_user_2fa__cookiename'
            ],
        ],
        'include' => []
    ],

    'user_logout' => [
        'method' => 'recordUser.cmsLogout',
        'note' => '#template.cmd_user_logout',
        'do' => 'do',
        'oneof' => '',
        'params' => [
            'submit' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text_value',
                'description' => '#template.cmd_user_logout__submit'
            ],
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_user_logout__csrf'
            ],
            'deletecookie' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_user_logout__deletecookie'
            ],
            'cookiename' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'usess',
                'value' => '#template.cmdval_cookie',
                'description' => '#template.cmd_user_logout__cookiename'
            ]
        ],
        'include' => [
        ]
    ],

    'user_protect' => [
        'method' => 'recordUser.cmsProtect',
        'note' => '#template.cmd_user_protect',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'errortemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_template_name',
                'description' => '#template.cmd_user_protect__errortemplate'
            ],
            'filter' => [
                'ref' => ['area' => 'area', 'area_id' => 'area', 'record_id' => 'record', 'id' => 'record'],
                'operator' => true,
                'mandatory' => false,
                'subparam' => 2,
                'default' => '',
                'value' => '#template.cmdval_select_filter',
                'description' => '#template.cmd_user_protect__filter'
            ],
            'sessionid' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_session_id',
                'description' => '#template.cmd_user_protect__sessionid'
            ],
            'cookiename' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'usess',
                'value' => '#template.cmdval_cookie',
                'description' => '#template.cmd_user_protect__cookiename'
            ]
        ],
        'include' => [
        ]
    ],

    'user_pw' => [
        'method' => 'recordUser.cmsPw',
        'note' => '#template.cmd_user_pw',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'formguard' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => '#template.cmdval_true_false_seconds',
                'description' => '#template.cmd_user_pw__formguard'
            ],
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_user_pw__csrf'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => '#template.cmd_user_pw__successtemplate'
            ],
            'errortemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => '#template.cmd_user_pw__errortemplate'
            ],
            'container' => [
                'ref' => 'area',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_container',
                'description' => '#template.cmd_user_pw__container'
            ],
            'uniquefield' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_field_name',
                'description' => '#template.cmd_user_pw__uniquefield'
            ],
            'uniquevalue' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_user_pw__uniquevalue'
            ],
            'submit' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text_value',
                'description' => '#template.cmd_user_pw__submit'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_text',
                'description' => '#template.cmd_user_pw__message'
            ]
        ],
        'include' => [
        ]
    ],

    'user_register' => [
        'method' => 'recordUser.cmsRegister',
        'note' => '#template.cmd_user_register',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'formguard' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'false',
                'value' => '#template.cmdval_true_false_seconds',
                'description' => '#template.cmd_user_register__formguard'
            ],
            'csrf' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'true',
                'value' => 'true / false',
                'description' => '#template.cmd_user_register__csrf'
            ],
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => '#template.cmd_user_register__successtemplate'
            ],
            'container' => [
                'ref' => 'area',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_container',
                'description' => '#template.cmd_user_register__container'
            ],
            'emailfield' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_field_name',
                'description' => '#template.cmd_user_register__emailfield'
            ],
            'fields' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_comma_list',
                'description' => '#template.cmd_user_register__fields'
            ],
            'overwrite' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_key_value_pairs',
                'description' => '#template.cmd_user_register__overwrite'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_message',
                'description' => '#template.cmd_user_register__message'
            ],
            'submit' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_text_value',
                'description' => '#template.cmd_user_register__submit'
            ],
            'owner' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_user_id',
                'description' => '#template.cmd_user_register__owner'
            ],
            'data' => [
                'mandatory' => false,
                'subparam' => 0,
                'default' => 'data',
                'value' => '#template.cmdval_php_variable',
                'description' => '#template.cmd_user_register__data'
            ]
        ],
        'include' => [
        ]
    ],

    'user_register_confirm' => [
        'method' => 'recordUser.cmsRegisterConfirm',
        'note' => '#template.cmd_user_register_confirm',
        'do' => 'do',
        'oneof' => '',
        'php_suffix' => 'include_and_stop',
        'params' => [
            'successtemplate' => [
                'ref' => 'template',
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '',
                'description' => '#template.cmd_user_register_confirm__successtemplate'
            ],
            'hash' => [
                'mandatory' => true,
                'subparam' => 0,
                'default' => '',
                'value' => '#template.cmdval_hash_id',
                'description' => '#template.cmd_user_register_confirm__hash'
            ],
            'message' => [
                'mandatory' => false,
                'subparam' => 1,
                'default' => '',
                'value' => '#template.cmdval_message',
                'description' => '#template.cmd_user_register_confirm__message'
            ]
        ],
        'include' => [
        ]
    ],
];