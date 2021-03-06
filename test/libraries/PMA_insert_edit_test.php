<?php
/* vim: set expandtab sw=4 ts=4 sts=4: */
/**
 * Tests for libraries/insert_edit.lib.php
 *
 * @package PhpMyAdmin-test
 */

/*
 * Include to test.
 */
require_once 'libraries/insert_edit.lib.php';

require_once 'libraries/database_interface.inc.php';
require_once 'libraries/Util.class.php';
require_once 'libraries/url_generating.lib.php';
require_once 'libraries/php-gettext/gettext.inc';
require_once 'libraries/Types.class.php';
require_once 'libraries/js_escape.lib.php';
require_once 'libraries/relation.lib.php';
require_once 'libraries/Message.class.php';
require_once 'libraries/transformations.lib.php';
require_once 'libraries/Theme.class.php';
require_once 'libraries/Response.class.php';
require_once 'libraries/sanitizing.lib.php';
require_once 'libraries/Table.class.php';

/**
 * Tests for libraries/insert_edit.lib.php
 *
 * @package PhpMyAdmin-test
 */
class PMA_InsertEditTest extends PHPUnit_Framework_TestCase
{
    /**
     * Setup for test cases
     *
     * @return void
     */
    public function setup()
    {
        $GLOBALS['server'] = 1;
        $_SESSION['PMA_Theme'] = PMA_Theme::load('./themes/pmahomme');
        $GLOBALS['pmaThemeImage'] = 'theme/';
        $GLOBALS['PMA_PHP_SELF'] = 'index.php';
        $GLOBALS['cfg']['ServerDefault'] = 1;
        $GLOBALS['available_languages']= array(
            "en" => array("English", "US-ENGLISH")
        );
        $GLOBALS['text_dir'] = 'ltr';
        $GLOBALS['db'] = 'db';
        $GLOBALS['table'] = 'table';
    }

    /**
     * Test for PMA_getFormParametersForInsertForm
     *
     * @return void
     */
    public function testGetFormParametersForInsertForm()
    {
        $where_clause = array('foo' => 'bar ', '1' => ' test');
        $_REQUEST['clause_is_unique'] = false;
        $_REQUEST['sql_query'] = 'SELECT a';
        $GLOBALS['goto'] = 'index.php';

        $result = PMA_getFormParametersForInsertForm(
            'dbname', 'tablename', false, $where_clause, 'localhost'
        );

        $this->assertEquals(
            array(
                'db'        => 'dbname',
                'table'     => 'tablename',
                'goto'      => 'index.php',
                'err_url'   => 'localhost',
                'sql_query' => 'SELECT a',
                'where_clause[foo]' => 'bar',
                'where_clause[1]' => 'test',
                'clause_is_unique' => false
            ),
            $result
        );
    }

    /**
     * Test for PMA_getWhereClauseArray
     *
     * @return void
     */
    public function testGetWhereClauseArray()
    {
        $this->assertNull(
            PMA_getWhereClauseArray(null)
        );

        $this->assertEquals(
            array(1, 2, 3),
            PMA_getWhereClauseArray(array(1, 2, 3))
        );

        $this->assertEquals(
            array('clause'),
            PMA_getWhereClauseArray('clause')
        );
    }

    /**
     * Test for PMA_analyzeWhereClauses
     *
     * @return void
     */
    public function testAnalyzeWhereClause()
    {
        $clauses = array('a=1', 'b="fo\o"');

        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->at(0))
            ->method('query')
            ->with(
                'SELECT * FROM `db`.`table` WHERE a=1;',
                null,
                PMA_DatabaseInterface::QUERY_STORE
            )
            ->will($this->returnValue('result1'));

        $dbi->expects($this->at(3))
            ->method('query')
            ->with(
                'SELECT * FROM `db`.`table` WHERE b="fo\o";',
                null,
                PMA_DatabaseInterface::QUERY_STORE
            )
            ->will($this->returnValue('result2'));

        $dbi->expects($this->at(1))
            ->method('fetchAssoc')
            ->with('result1')
            ->will($this->returnValue(array('assoc1')));

        $dbi->expects($this->at(4))
            ->method('fetchAssoc')
            ->with('result2')
            ->will($this->returnValue(array('assoc2')));

        $GLOBALS['dbi'] = $dbi;
        $result = PMA_analyzeWhereClauses($clauses, 'table', 'db');

        $this->assertEquals(
            array(
                array('a=1', 'b="fo\\\\o"'),
                array('result1', 'result2'),
                array(
                    array('assoc1'),
                    array('assoc2')
                ),
                ''
            ),
            $result
        );
    }

    /**
     * Test for PMA_showEmptyResultMessageOrSetUniqueCondition
     *
     * @return void
     */
    public function testShowEmptyResultMessageOrSetUniqueCondition()
    {
        $temp = new stdClass;
        $temp->orgname = 'orgname';
        $temp->table = 'table';
        $temp->type = 'real';
        $temp->primary_key = 1;
        $meta_arr = array($temp);

        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->at(0))
            ->method('getFieldsMeta')
            ->with('result1')
            ->will($this->returnValue($meta_arr));

        $GLOBALS['dbi'] = $dbi;

        $result = PMA_showEmptyResultMessageOrSetUniqueCondition(
            array('1' => array('1' => 1)), 1, array(),
            'SELECT', array('1' => 'result1')
        );

        $this->assertTrue($result);

        // case 2
        $GLOBALS['cfg']['ShowSQL'] = false;

        $responseMock = $this->getMockBuilder('PMA_Response')
            ->disableOriginalConstructor()
            ->setMethods(array('addHtml'))
            ->getMock();

        $response = new ReflectionProperty('PMA_Response', '_instance');
        $response->setAccessible(true);
        $response->setValue(null, $responseMock);

        $result = PMA_showEmptyResultMessageOrSetUniqueCondition(
            array(false), 0, array('1'), 'SELECT', array('1' => 'result1')
        );

        $this->assertFalse($result);
    }

    /**
     * Test for PMA_loadFirstRow
     *
     * @return void
     */
    public function testLoadFirstRow()
    {
        $GLOBALS['cfg']['InsertRows'] = 2;

        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->at(0))
            ->method('query')
            ->with(
                'SELECT * FROM `db`.`table` LIMIT 1;',
                null,
                PMA_DatabaseInterface::QUERY_STORE
            )
            ->will($this->returnValue('result1'));

        $GLOBALS['dbi'] = $dbi;

        $result = PMA_loadFirstRow('table', 'db');

        $this->assertEquals(
            array('result1', array(false, false)),
            $result
        );
    }

    /**
     * Test for PMA_urlParamsInEditMode
     *
     * @return void
     */
    public function testUrlParamsInEditMode()
    {
        $where_clause_array = array('foo=1', 'bar=2');
        $_REQUEST['sql_query'] = 'SELECT 1';

        $result = PMA_urlParamsInEditMode(array(1), $where_clause_array, true);

        $this->assertEquals(
            array(
                '0' => 1,
                'where_clause' => 'bar=2',
                'sql_query' => 'SELECT 1'
            ),
            $result
        );
    }

    /**
     * Test for PMA_showFunctionFieldsInEditMode
     *
     * @return void
     */
    public function testShowFunctionFieldsInEditMode()
    {
        $GLOBALS['cfg']['ShowFieldTypesInDataEditView'] = true;
        $GLOBALS['cfg']['ServerDefault'] = 1;
        $url_params = array('ShowFunctionFields' => 2);

        $result = PMA_showFunctionFieldsInEditMode($url_params, false);

        $this->assertEquals(
            ' : <a href="tbl_change.php?ShowFunctionFields=1&amp;ShowFieldTypesIn'
            . 'DataEditView=1&amp;goto=sql.php&amp;lang=en&amp;token=token">'
            . 'Function</a>' . "\n",
            $result
        );

        // case 2
        $result = PMA_showFunctionFieldsInEditMode($url_params, true);

        $this->assertEquals(
            '<th><a href="tbl_change.php?ShowFunctionFields=0&amp;ShowFieldTypesIn'
            . 'DataEditView=1&amp;goto=sql.php&amp;lang=en&amp;token=token" title='
            . '"Hide">Function</a></th>' . "\n",
            $result
        );
    }

    /**
     * Test for PMA_showColumnTypesInDataEditView
     *
     * @return void
     */
    public function testShowColumnTypesInDataEditView()
    {
        $GLOBALS['cfg']['ShowFieldTypesInDataEditView'] = true;
        $GLOBALS['cfg']['ShowFunctionFields'] = true;
        $GLOBALS['cfg']['ServerDefault'] = 1;
        $url_params = array('ShowFunctionFields' => 2);

        $result = PMA_showColumnTypesInDataEditView($url_params, false);

        $this->assertEquals(
            ' : <a href="tbl_change.php?ShowFunctionFields=1&amp;ShowFieldTypesIn'
            . 'DataEditView=1&amp;goto=sql.php&amp;lang=en&amp;token=token">'
            . 'Type</a>' . "\n",
            $result
        );

        // case 2
        $result = PMA_showColumnTypesInDataEditView($url_params, true);

        $this->assertEquals(
            '<th><a href="tbl_change.php?ShowFunctionFields=1&amp;ShowFieldTypesIn'
            . 'DataEditView=0&amp;goto=sql.php&amp;lang=en&amp;token=token" title='
            . '"Hide">Type</a></th>' . "\n",
            $result
        );
    }

    /**
     * Test for PMA_getDefaultForDatetime
     *
     * @return void
     */
    public function testGetDefaultForDatetime()
    {
        $column['Type'] = 'datetime';
        $column['Null'] = 'YES';

        $this->assertNull(
            PMA_getDefaultForDatetime($column) //should be passed as reference?
        );
    }

    /**
     * Test for PMA_analyzeTableColumnsArray
     *
     * @return void
     */
    public function testAnalyzeTableColumnsArray()
    {
        $column = array(
            'Field' => '1<2',
            'Field_md5' => 'pswd',
            'Type' => 'float(10, 1)'
        );

        $result = PMA_analyzeTableColumnsArray(
            $column, array(), false
        );

        $this->assertEquals(
            $result['Field_html'],
            '1&lt;2'
        );

        $this->assertEquals(
            $result['Field_md5'],
            '4342210df36bf2ff2c4e2a997a6d4089'
        );

        $this->assertEquals(
            $result['True_Type'],
            'float'
        );

        $this->assertEquals(
            $result['len'],
            100
        );

        $this->assertEquals(
            $result['Field_title'],
            '1&lt;2'
        );

        $this->assertEquals(
            $result['is_binary'],
            false
        );

        $this->assertEquals(
            $result['is_blob'],
            false
        );

        $this->assertEquals(
            $result['is_char'],
            false
        );

        $this->assertEquals(
            $result['pma_type'],
            'float(10, 1)'
        );

        $this->assertEquals(
            $result['wrap'],
            ' nowrap'
        );

        $this->assertEquals(
            $result['Field'],
            '1<2'
        );
    }

    /**
     * Test for PMA_getColumnTitle
     *
     * @return void
     */
    public function testGetColumnTitle()
    {
        $column['Field'] = 'f1<';
        $column['Field_html'] = 'f1&lt;';

        $this->assertEquals(
            PMA_getColumnTitle($column, array()),
            'f1&lt;'
        );

        $comments['f1<'] = 'comment>';

        $result = PMA_getColumnTitle($column, $comments);

        $this->assertContains(
            'title="comment&gt;"',
            $result
        );

        $this->assertContains(
            'f1&lt;',
            $result
        );
    }

    /**
     * Test for PMA_isColumnBinary
     *
     * @return void
     */
    public function testIsColumnBinary()
    {
        $column['Type'] = 'binaryfoo';
        $this->assertEquals('binaryfoo', PMA_isColumnBinary($column));

        $column['Type'] = 'Binaryfoo';
        $this->assertEquals('Binaryfoo', PMA_isColumnBinary($column));

        $column['Type'] = 'varbinaryfoo';
        $this->assertEquals('binaryfoo', PMA_isColumnBinary($column));

        $column['Type'] = 'barbinaryfoo';
        $this->assertFalse(PMA_isColumnBinary($column));
    }

    /**
     * Test for PMA_isColumnBlob
     *
     * @return void
     */
    public function testIsColumnBlog()
    {
        $column['Type'] = 'blob';
        $this->assertEquals('blob', PMA_isColumnBlob($column));

        $column['Type'] = 'bloB';
        $this->assertEquals('bloB', PMA_isColumnBlob($column));

        $column['Type'] = 'mediumBloB';
        $this->assertEquals('BloB', PMA_isColumnBlob($column));

        $column['Type'] = 'tinyblobabc';
        $this->assertEquals('blobabc', PMA_isColumnBlob($column));

        $column['Type'] = 'longblob';
        $this->assertEquals('blob', PMA_isColumnBlob($column));

        $column['Type'] = 'foolongblobbar';
        $this->assertFalse(PMA_isColumnBlob($column));
    }

    /**
     * Test for PMA_iscolumnchar
     *
     * @return void
     */
    public function testIsColumnChar()
    {
        $column['Type'] = 'char(10)';
        $this->assertEquals('char(10)', PMA_iscolumnchar($column));

        $column['Type'] = 'VarChar(20)';
        $this->assertEquals('Char(20)', PMA_iscolumnchar($column));

        $column['Type'] = 'foochar';
        $this->assertFalse(PMA_iscolumnchar($column));
    }

    /**
     * Test for PMA_getEnumSetAndTimestampColumns
     *
     * @return void
     */
    public function testGetEnumAndTimestampColumns()
    {
        $column['True_Type'] = 'set';
        $this->assertEquals(
            array('set', '', false),
            PMA_getEnumSetAndTimestampColumns($column, false)
        );

        $column['True_Type'] = 'enum';
        $this->assertEquals(
            array('enum', '', false),
            PMA_getEnumSetAndTimestampColumns($column, false)
        );

        $column['True_Type'] = 'timestamp';
        $column['Type'] = 'date';
        $this->assertEquals(
            array('date', ' nowrap', true),
            PMA_getEnumSetAndTimestampColumns($column, false)
        );

        $column['True_Type'] = 'timestamp';
        $column['Type'] = 'date';
        $this->assertEquals(
            array('date', ' nowrap', false),
            PMA_getEnumSetAndTimestampColumns($column, true)
        );

        $column['True_Type'] = 'SET';
        $column['Type'] = 'num';
        $this->assertEquals(
            array('num', ' nowrap', false),
            PMA_getEnumSetAndTimestampColumns($column, false)
        );

        $column['True_Type'] = '';
        $column['Type'] = 'num';
        $this->assertEquals(
            array('num', ' nowrap', false),
            PMA_getEnumSetAndTimestampColumns($column, false)
        );
    }

    /**
     * Test for PMA_getFunctionColumn
     *
     * @return void
     */
    public function testGetFunctionColumn()
    {
        $GLOBALS['cfg']['ProtectBinary'] = true;
        $column['is_blob'] = true;
        $this->assertTag(
            PMA_getTagArray('<td class="center">', array('content' => 'Binary')),
            PMA_getFunctionColumn($column, false, '', '', '', '', '', '', '')
        );

        $GLOBALS['cfg']['ProtectBinary'] = 'all';
        $column['is_binary'] = true;
        $this->assertTag(
            PMA_getTagArray('<td class="center">', array('content' => 'Binary')),
            PMA_getFunctionColumn($column, true, '', '', '', '', '', '', '')
        );

        $GLOBALS['cfg']['ProtectBinary'] = 'noblob';
        $column['is_blob'] = false;
        $this->assertTag(
            PMA_getTagArray('<td class="center">', array('content' => 'Binary')),
            PMA_getFunctionColumn($column, true, '', '', '', '', '', '', '')
        );

        $GLOBALS['cfg']['ProtectBinary'] = false;
        $column['True_Type'] = 'enum';
        $this->assertTag(
            PMA_getTagArray('<td class="center">', array('content' => '--')),
            PMA_getFunctionColumn($column, true, '', '', '', '', '', '', '')
        );

        $column['True_Type'] = 'set';
        $this->assertTag(
            PMA_getTagArray('<td class="center">', array('content' => '--')),
            PMA_getFunctionColumn($column, true, '', '', '', '', '', '', '')
        );

        $column['True_Type'] = '';
        $column['pma_type'] = 'int';
        $this->assertTag(
            PMA_getTagArray('<td class="center">', array('content' => '--')),
            PMA_getFunctionColumn(
                $column, true, '', '', array('int'), '', '', '', ''
            )
        );

        $GLOBALS['PMA_Types'] = new PMA_Types;
        $column['Field'] = 'num';
        $this->assertContains(
            '<select name="funcsa" b tabindex="5" id="field_3_1"',
            PMA_getFunctionColumn(
                $column, true, 'a', 'b', array(), 2, 3, 3, ''
            )
        );
    }

    /**
     * Test for PMA_getNullColumn
     *
     * @return void
     */
    public function testGetNullColumn()
    {
        $column['Null'] = 'YES';
        $column['first_timestamp'] = false;
        $column['True_Type'] = 'enum';
        $column['Type'] = 0;
        $column['Field_md5'] = 'foobar';

        $result = PMA_getNullColumn(
            $column, 'a', true, 2, 0, 1, "<script>", '', ''
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="fields_null_preva" value="on" />'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="checkbox" class="checkbox_null" tabindex="2" '
                . 'name="fields_nulla" checked="checked" id="field_1_2" '
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" class="nullify_code" name="nullify_codea" '
                . 'value="2" '
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" class="hashed_field" name="hashed_fielda" '
                . 'value="foobar" />'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" class="multi_edit" name="multi_edita" '
                . 'value="<script>"'
            ),
            $result
        );

        // case 2
        $column['Null'] = 'NO';
        $result = PMA_getNullColumn(
            $column, 'a', true, 2, 0, 1, "<script>", '', ''
        );

        $this->assertEquals(
            "<td></td>\n",
            $result
        );
    }

    /**
     * Test for PMA_getNullifyCodeForNullColumn
     *
     * @return void
     */
    public function testGetNullifyCodeForNullColumn()
    {
        $column['True_Type'] = 'enum';
        $column['Type'] = 'ababababababababababa';
        $this->assertEquals(
            '1',
            PMA_getNullifyCodeForNullColumn($column, null, null)
        );

        $column['True_Type'] = 'enum';
        $column['Type'] = 'abababababababababab';
        $this->assertEquals(
            '2',
            PMA_getNullifyCodeForNullColumn($column, null, null)
        );

        $column['True_Type'] = 'set';
        $this->assertEquals(
            '3',
            PMA_getNullifyCodeForNullColumn($column, null, null)
        );

        $column['True_Type'] = '';
        $column['Field'] = 'f';
        $foreigners['f'] = true;
        $foreignData['foreign_link'] = '';
        $this->assertEquals(
            '4',
            PMA_getNullifyCodeForNullColumn($column, $foreigners, $foreignData)
        );
    }

    /**
     * Test for PMA_getForeignLink
     *
     * @return void
     */
    public function testGetForeignLink()
    {
        $column['Field'] = 'f';
        $titles['Browse'] = "'";
        $GLOBALS['cfg']['ServerDefault'] = 2;
        $result = PMA_getForeignLink(
            $column, 'a', 'b', 'd', 2, 0, 1, "abc", array('tbl', 'db'), 8, $titles
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="fields_typeb" value="foreign"'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<a class="foreign_values_anchor" target="_blank" onclick='
                . '"window.open(this.href,\'foreigners\', \'width=640,height=240,'
                . 'scrollbars=yes,resizable=yes\'); return false;" href="browse_'
                . 'foreigners.php?db=db&table=tbl&field=f&rownumber=8&data=abc'
                . '&server=1&lang=en&token=token">',
                array('content' => "\\'")
            ),
            $result
        );

        $this->assertContains(
            '<input type="text" name="fieldsb" class="textfield" d tabindex="2" '
            . 'id="field_1_3" value="abc"',
            $result
        );

    }

    /**
     * Test for PMA_dispRowForeignData
     *
     * @return void
     */
    public function testDispRowForeignData()
    {
        $foreignData['disp_row'] = array();
        $foreignData['foreign_field'] = null;
        $foreignData['foreign_display'] = null;
        $GLOBALS['cfg']['ForeignKeyMaxLimit'] = 1;
        $GLOBALS['cfg']['NaturalOrder'] = false;
        $result = PMA_dispRowForeignData(
            'a', 'b', 'd', 2, 0, 1, "<s>", $foreignData
        );

        $this->assertContains(
            "a\n",
            $result
        );

        $this->assertContains(
            '<select name="fieldsb" d class="textfield" tabindex="2" '
            . 'id="field_1_3">',
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="fields_typeb" value="foreign"'
            ),
            $result
        );
    }

    /**
     * Test for PMA_getTextarea
     *
     * @return void
     */
    public function testGetTextarea()
    {
        $GLOBALS['cfg']['TextareaRows'] = 20;
        $GLOBALS['cfg']['TextareaCols'] = 10;
        $GLOBALS['cfg']['CharTextareaRows'] = 5;
        $GLOBALS['cfg']['CharTextareaCols'] = 1;

        $column['is_char'] = true;
        $result = PMA_getTextarea(
            $column, 'a', 'b', 'd', 2, 0, 1, "abc/", 'foobar'
        );

        $this->assertTag(
            PMA_getTagArray(
                '<textarea name="fieldsb" class="char" rows="5" cols="1" dir="abc/" '
                . 'id="field_1_3" tabindex="2">',
                array('content' => 'foobar')
            ),
            $result
        );

        $this->assertContains(
            " d ",
            $result
        );
    }

    /**
     * Test for PMA_getPmaTypeEnum
     *
     * @return void
     */
    public function testGetPmaTypeEnum()
    {
        $extracted_columnspec['enum_set_values'] = array();
        $column['Type'] = 'abababababababababab';
        $result = PMA_getPmaTypeEnum(
            $column, 'a', 'b', $extracted_columnspec, 'd', 2, 0, 1, 'foobar'
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="fields_typeb" value="enum" />'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="fieldsb" value=""'
            ),
            $result
        );

        $column['Type'] = 'ababababababababababa';
        $result = PMA_getPmaTypeEnum(
            $column, 'a', 'b', $extracted_columnspec, 'd', 2, 0, 1, 'foobar'
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="fields_typeb" value="enum"'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="fieldsb" value="" />'
            ),
            $result
        );

        $this->assertContains(
            '<select name="fieldsb" d class="textfield" tabindex="2" '
            . 'id="field_1_3">',
            $result
        );
    }

    /**
     * Test for PMA_getColumnEnumValues
     *
     * @return void
     */
    public function testGetColumnEnumValues()
    {
        $extracted_columnspec['enum_set_values'] = array(
            '<abc>', '"foo"'
        );

        $column['values'] = 'abc';

        $result = PMA_getColumnEnumValues($column, $extracted_columnspec);
        $this->assertEquals(
            array(
                array('plain' => '<abc>', 'html' => '&lt;abc&gt;'),
                array('plain' => '"foo"', 'html' => '&quot;foo&quot;'),
            ),
            $result
        );
    }

    /**
     * Test for PMA_getDropDownDependingOnLength
     *
     * @return void
     */
    public function testGetDropDownDependingOnLength()
    {
        $column_enum_values = array(
            array(
                'html' => 'foo',
                'plain' => 'data'
            ),
            array(
                'html' => 'bar',
                'plain' => ''
            )
        );

        $result = PMA_getDropDownDependingOnLength(
            array(), 'a', 'b', 2, 0, 1, 'data', $column_enum_values
        );

        $this->assertContains(
            '<select name="fieldsa" b class="textfield" tabindex="2" '
            . 'id="field_1_3">',
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<option value="foo" selected="selected">',
                array('content' => 'foo')
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<option value="bar">',
                array('content' => 'bar')
            ),
            $result
        );

        // case 2
        $column_enum_values = array(
            array(
                'html' => 'foo',
                'plain' => 'data'
            )
        );

        $column['Default'] = 'data';
        $column['Null'] = 'YES';
        $result = PMA_getDropDownDependingOnLength(
            $column, 'a', 'b', 2, 0, 1, '', $column_enum_values
        );

        $this->assertTag(
            PMA_getTagArray(
                '<option value="foo" selected="selected">',
                array('content' => 'foo')
            ),
            $result
        );
    }

    /**
     * Test for PMA_getRadioButtonDependingOnLength
     *
     * @return void
     */
    public function testGetRadioButtonDependingOnLength()
    {
        $column_enum_values = array(
            array(
                'html' => 'foo',
                'plain' => 'data'
            ),
            array(
                'html' => 'bar',
                'plain' => ''
            )
        );

        $result = PMA_getRadioButtonDependingOnLength(
            'a', 'b', 2, array(), 0, 1, 'data', $column_enum_values
        );

        $this->assertContains(
            '<input type="radio" name="fieldsa" class="textfield" value="foo" '
            . 'id="field_1_3_0" b checked="checked" tabindex="2" />',
            $result
        );

        $this->assertContains(
            '<label for="field_1_3_0">foo</label>',
            $result
        );

        $this->assertContains(
            '<input type="radio" name="fieldsa" class="textfield" value="bar" '
            . 'id="field_1_3_1" b tabindex="2" />',
            $result
        );

        $this->assertContains(
            '<label for="field_1_3_1">bar</label>',
            $result
        );

        // case 2
        $column_enum_values = array(
            array(
                'html' => 'foo',
                'plain' => 'data'
            )
        );

        $column['Default'] = 'data';
        $column['Null'] = 'YES';
        $result = PMA_getRadioButtonDependingOnLength(
            'a', 'b', 2, $column, 0, 1, '', $column_enum_values
        );

        $this->assertContains(
            '<input type="radio" name="fieldsa" class="textfield" value="foo" '
            . 'id="field_1_3_0" b checked="checked" tabindex="2" />',
            $result
        );
    }

    /**
     * Test for PMA_getPmaTypeSet
     *
     * @return void
     */
    public function testGetPmaTypeSet()
    {
        $column['values']  = array(
            array(
                'html' => '&lt;',
                'plain' => '<'
            )
        );

        $column['select_size'] = 1;

        $result = PMA_getPmaTypeSet(
            $column, null, 'a', 'b', 'c', 2, 0, 1, 'data,<'
        );

        $this->assertContains("a\n", $result);

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="fields_typeb" value="set" />'
            ),
            $result
        );

        $this->assertContains(
            '<option value="&lt;" selected="selected">&lt;</option>',
            $result
        );

        $this->assertContains(
            '<select name="fieldsb[]" class="textfield" size="1" '
            . 'multiple="multiple" c tabindex="2" id="field_1_3">',
            $result
        );
    }

    /**
     * Test for PMA_getColumnSetValueAndSelectSize
     *
     * @return void
     */
    public function testGetColumnSetValueAndSelectSize()
    {
        $extracted_columnspec['enum_set_values'] = array('a', '<');
        $result = PMA_getColumnSetValueAndSelectSize(array(), $extracted_columnspec);

        $this->assertEquals(
            array(
                array(
                    array('plain' => 'a', 'html' => 'a'),
                    array('plain' => '<', 'html' => '&lt;')
                ),
                2
            ),
            $result
        );

        $column['values'] = array(1, 2);
        $column['select_size'] = 3;
        $result = PMA_getColumnSetValueAndSelectSize($column, $extracted_columnspec);

        $this->assertEquals(
            array(
                array(1, 2),
                3
            ),
            $result
        );
    }

    /**
     * Test for PMA_getBinaryAndBlobColumn
     *
     * @return void
     */
    public function testGetBinaryAndBlobColumn()
    {
        $GLOBALS['cfg']['ProtectBinary'] = true;
        $column['is_blob'] = true;
        $column['Field_md5'] = '123';
        $column['pma_type'] = 'blob';
        $column['True_Type'] = 'blob';
        $GLOBALS['max_upload_size'] = 65536;

        $result = PMA_getBinaryAndBlobColumn(
            $column, '12\\"23', null, 20, 'a', 'b', 'c', 2, 1, 1, '/', null,
            'foo', true
        );

        $this->assertEquals(
            'Binary - do not edit (5 B)<input type="hidden" name="fields_typeb" '
            . 'value="protected" /><input type="hidden" name="fieldsb" value="" />'
            . '<br /><input type="file" name="fields_uploadfoo[123]" class="text'
            . 'field" id="field_1_3" size="10" c/>&nbsp;(Max: 64KiB)' . "\n",
            $result
        );

        // case 2
        $GLOBALS['cfg']['ProtectBinary'] = "all";
        $column['is_binary'] = true;

        $result = PMA_getBinaryAndBlobColumn(
            $column, '1223', null, 20, 'a', 'b', 'c', 2, 1, 1, '/', null,
            'foo', false
        );

        $this->assertEquals(
            'Binary - do not edit (4 B)<input type="hidden" name="fields_typeb" '
            . 'value="protected" /><input type="hidden" name="fieldsb" value="" '
            . '/>',
            $result
        );

        // case 3
        $GLOBALS['cfg']['ProtectBinary'] = "noblob";
        $column['is_blob'] = false;

        $result = PMA_getBinaryAndBlobColumn(
            $column, '1223', null, 20, 'a', 'b', 'c', 2, 1, 1, '/', null,
            'foo', true
        );

        $this->assertEquals(
            'Binary - do not edit (4 B)<input type="hidden" name="fields_typeb" '
            . 'value="protected" /><input type="hidden" name="fieldsb" value="" '
            . '/>',
            $result
        );

        // case 4
        $GLOBALS['cfg']['ProtectBinary'] = false;
        $column['is_blob'] = true;
        $column['is_char'] = true;
        $GLOBALS['cfg']['TextareaRows'] = 20;
        $GLOBALS['cfg']['TextareaCols'] = 10;
        $GLOBALS['cfg']['CharTextareaRows'] = 5;
        $GLOBALS['cfg']['CharTextareaCols'] = 1;

        $result = PMA_getBinaryAndBlobColumn(
            $column, '1223', null, 20, 'a', 'b', 'c', 2, 1, 1, '/', null,
            'foo', true
        );

        $this->assertEquals(
            "\na\n"
            . '<textarea name="fieldsb" class="char" rows="5" cols="1" dir="/" '
            . 'id="field_1_3" c tabindex="3"></textarea><br /><input type="file" '
            . 'name="fields_uploadfoo[123]" class="textfield" id="field_1_3" '
            . 'size="10" c/>&nbsp;(Max: 64KiB)' . "\n",
            $result
        );

        // case 5
        $GLOBALS['cfg']['ProtectBinary'] = false;
        $GLOBALS['cfg']['LongtextDoubleTextarea'] = true;
        $GLOBALS['cfg']['LimitChars'] = 100;
        $column['is_blob'] = false;
        $column['len'] = 255;
        $column['is_char'] = false;
        $GLOBALS['cfg']['TextareaRows'] = 20;
        $GLOBALS['cfg']['TextareaCols'] = 10;

        $result = PMA_getBinaryAndBlobColumn(
            $column, '1223', null, 20, 'a', 'b', 'c', 2, 1, 1, '/', null,
            'foo', true
        );

        $this->assertEquals(
            "\na\n"
            . '<textarea name="fieldsb" class="" rows="20" cols="10" dir="/" '
            . 'id="field_1_3" c tabindex="3"></textarea>',
            $result
        );

        // case 6
        $column['is_blob'] = false;
        $column['len'] = 10;
        $GLOBALS['cfg']['LimitChars'] = 40;

        /**
         * This condition should be tested, however, it gives an undefined function
         * PMA_getFileSelectOptions error:
         * $GLOBALS['cfg']['UploadDir'] = true;
         *
         */

        $result = PMA_getBinaryAndBlobColumn(
            $column, '1223', null, 20, 'a', 'b', 'c', 2, 1, 1, '/', null,
            'foo', true
        );

        $this->assertEquals(
            "\na\n"
            . '<input type="text" name="fieldsb" value="" size="10" class='
            . '"textfield" c tabindex="3" id="field_1_3" />',
            $result
        );
    }

    /**
     * Test for PMA_getHTMLinput
     *
     * @return void
     */
    public function testGetHTMLinput()
    {
        $column['pma_type'] = 'date';
        $column['True_Type'] = 'date';
        $result = PMA_getHTMLinput($column, 'a', 'b', 30, 'c', 23, 2, 0);

        $this->assertEquals(
            '<input type="text" name="fieldsa" value="b" size="30" class='
            . '"textfield datefield" c tabindex="25" id="field_0_3" />',
            $result
        );

        // case 2 datetime
        $column['pma_type'] = 'datetime';
        $column['True_Type'] = 'datetime';
        $result = PMA_getHTMLinput($column, 'a', 'b', 30, 'c', 23, 2, 0);
        $this->assertEquals(
            '<input type="text" name="fieldsa" value="b" size="30" class='
            . '"textfield datetimefield" c tabindex="25" id="field_0_3" />',
            $result
        );

        // case 3 timestamp
        $column['pma_type'] = 'timestamp';
        $column['True_Type'] = 'timestamp';
        $result = PMA_getHTMLinput($column, 'a', 'b', 30, 'c', 23, 2, 0);
        $this->assertEquals(
            '<input type="text" name="fieldsa" value="b" size="30" class='
            . '"textfield datetimefield" c tabindex="25" id="field_0_3" />',
            $result
        );
    }

    /**
     * Test for PMA_getMaxUploadSize
     *
     * @return void
     */
    public function testGetMaxUploadSize()
    {
        $GLOBALS['max_upload_size'] = 257;
        $column['pma_type'] = 'tinyblob';
        $result = PMA_getMaxUploadSize($column, 256);

        $this->assertEquals(
            array("(Max: 256B)\n", 256),
            $result
        );

        // case 2
        $GLOBALS['max_upload_size'] = 250;
        $column['pma_type'] = 'tinyblob';
        $result = PMA_getMaxUploadSize($column, 20);

        $this->assertEquals(
            array("(Max: 250B)\n", 250),
            $result
        );
    }

    /**
     * Test for PMA_getValueColumnForOtherDatatypes
     *
     * @return void
     */
    public function testGetValueColumnForOtherDatatypes()
    {
        $column['len'] = 20;
        $column['is_char'] = true;
        $GLOBALS['cfg']['CharEditing'] = '';
        $GLOBALS['cfg']['MaxSizeForInputField'] = 30;
        $GLOBALS['cfg']['MinSizeForInputField'] = 10;
        $GLOBALS['cfg']['TextareaRows'] = 20;
        $GLOBALS['cfg']['TextareaCols'] = 10;
        $GLOBALS['cfg']['CharTextareaRows'] = 5;
        $GLOBALS['cfg']['CharTextareaCols'] = 1;

        $extracted_columnspec['spec_in_brackets'] = 25;
        $result = PMA_getValueColumnForOtherDatatypes(
            $column, 'defchar', 'a', 'b', 'c', 22, '&lt;', 12, 1, "/", "&lt;",
            "foo\nbar", $extracted_columnspec
        );

        $this->assertEquals(
            "a\n\na\n"
            . '<textarea name="fieldsb" class="char" rows="5" cols="1" dir="/" '
            . 'id="field_1_3" c tabindex="34">&lt;</textarea>',
            $result
        );

        // case 2: (else)
        $column['is_char'] = false;
        $column['Extra'] = 'auto_increment';
        $column['pma_type'] = 'timestamp';
        $column['True_Type'] = 'timestamp';
        $result = PMA_getValueColumnForOtherDatatypes(
            $column, 'defchar', 'a', 'b', 'c', 22, '&lt;', 12, 1, "/", "&lt;",
            "foo\nbar", $extracted_columnspec
        );

        $this->assertEquals(
            "a\n"
            . '<input type="text" name="fieldsb" value="&lt;" size="20" class="text'
            . 'field datetimefield" c tabindex="34" id="field_1_3" /><input type='
            . '"hidden" name="auto_incrementb" value="1" /><input type="hidden" name'
            . '="fields_typeb" value="timestamp" />',
            $result
        );

        // case 3: (else -> datetime)
        $column['pma_type'] = 'datetime';
        $result = PMA_getValueColumnForOtherDatatypes(
            $column, 'defchar', 'a', 'b', 'c', 22, '&lt;', 12, 1, "/", "&lt;",
            "foo\nbar", $extracted_columnspec
        );

        $this->assertContains(
            '<input type="hidden" name="fields_typeb" value="datetime" />',
            $result
        );
    }

    /**
     * Test for PMA_getColumnSize
     *
     * @return void
     */
    public function testGetColumnSize()
    {
        $column['is_char'] = true;
        $extracted_columnspec['spec_in_brackets'] = 45;
        $GLOBALS['cfg']['MinSizeForInputField'] = 30;
        $GLOBALS['cfg']['MaxSizeForInputField'] = 40;

        $this->assertEquals(
            40,
            PMA_getColumnSize($column, $extracted_columnspec)
        );

        $this->assertEquals(
            'textarea',
            $GLOBALS['cfg']['CharEditing']
        );

        // case 2
        $column['is_char'] = false;
        $column['len'] = 20;
        $this->assertEquals(
            30,
            PMA_getColumnSize($column, $extracted_columnspec)
        );
    }

    /**
     * Test for PMA_getHTMLforGisDataTypes
     *
     * @return void
     */
    public function testGetHTMLforGisDataTypes()
    {
        $GLOBALS['cfg']['ActionLinksMode'] = 'icons';
        $GLOBALS['cfg']['LinkLengthLimit'] = 2;
        $this->assertContains(
            '<a href="#" target="_blank"><span class="nowrap"><img src="themes/dot.'
            . 'gif" title="Edit/Insert" alt="Edit/Insert" class="icon ic_b_edit" />'
            . '</span></a>',
            PMA_getHTMLforGisDataTypes()
        );
    }

    /**
     * Test for PMA_getContinueInsertionForm
     *
     * @return void
     */
    public function testGetContinueInsertionForm()
    {
        $where_clause_array = array("a<b");
        $GLOBALS['cfg']['InsertRows'] = 1;
        $GLOBALS['cfg']['ServerDefault'] = 1;
        $GLOBALS['goto'] = "index.php";
        $_REQUEST['where_clause'] = true;
        $_REQUEST['sql_query'] = "SELECT 1";

        $result = PMA_getContinueInsertionForm(
            "tbl", "db", $where_clause_array, "localhost"
        );

        $this->assertTag(
            PMA_getTagArray(
                '<form id="continueForm" method="post" action="tbl_replace.php" '
                . 'name="continueForm">'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="db" value="db" />'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="table" value="tbl" />'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="goto" value="index.php" />'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="err_url" value="localhost" />'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="sql_query" value="SELECT 1" />'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="hidden" name="where_clause[0]" value="a<b" />'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<option value="1" selected="selected">',
                array('content' => '1')
            ),
            $result
        );
    }

    /**
     * Test for PMA_getActionsPanel
     *
     * @return void
     */
    public function testGetActionsPanel()
    {
        $GLOBALS['cfg']['ShowHint'] = false;
        $result = PMA_getActionsPanel(null, 'back', 2, 1, null);

        $this->assertTag(
            PMA_getTagArray(
                '<select name="submit_type" class="control_at_footer" tabindex="4">'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<select name="after_insert">'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="submit" class="control_at_footer" value="Go" '
                . 'tabindex="9" id="buttonYes" '
            ),
            $result
        );
    }

    /**
     * Test for PMA_getSubmitTypeDropDown
     *
     * @return void
     */
    public function testGetSubmitTypeDropDown()
    {
        $result = PMA_getSubmitTypeDropDown(true, 2, 2);

        $this->assertTag(
            PMA_getTagArray(
                '<select name="submit_type" class="control_at_footer" tabindex="5">'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<option value="save">',
                array('content' => 'Save')
            ),
            $result
        );
    }

    /**
     * Test for PMA_getAfterInsertDropDown
     *
     * @return void
     */
    public function testGetAfterInsertDropDown()
    {
        $result = PMA_getAfterInsertDropDown("`t`.`f` = 2", 'new_insert', true);

        $this->assertTag(
            PMA_getTagArray(
                '<option value="new_insert" selected="selected">'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<option value="same_insert"'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<option value="edit_next" >'
            ),
            $result
        );
    }

    /**
     * Test for PMA_getSumbitAndResetButtonForActionsPanel
     *
     * @return void
     */
    public function testGetSumbitAndResetButtonForActionsPanel()
    {
        $GLOBALS['cfg']['ShowHint'] = false;
        $result = PMA_getSumbitAndResetButtonForActionsPanel(1, 0);

        $this->assertTag(
            PMA_getTagArray(
                '<input type="submit" class="control_at_footer" value="Go" '
                . 'tabindex="7" id="buttonYes" />'
            ),
            $result
        );

        $this->assertTag(
            PMA_getTagArray(
                '<input type="reset" class="control_at_footer" value="Reset" '
                . 'tabindex="8" />'
            ),
            $result
        );
    }

    /**
     * Test for PMA_getHeadAndFootOfInsertRowTable
     *
     * @return void
     */
    public function testGetHeadAndFootOfInsertRowTable()
    {
        $GLOBALS['cfg']['ShowFieldTypesInDataEditView'] = true;
        $GLOBALS['cfg']['ShowFunctionFields'] = true;
        $GLOBALS['cfg']['ServerDefault'] = 1;
        $url_params = array('ShowFunctionFields' => 2);

        $result = PMA_getHeadAndFootOfInsertRowTable($url_params);

        $this->assertContains(
            'tbl_change.php?ShowFunctionFields=1&amp;ShowFieldTypesInDataEditView=0',
            $result
        );

        $this->assertContains(
            'tbl_change.php?ShowFunctionFields=0&amp;ShowFieldTypesInDataEditView=1',
            $result
        );
    }

    /**
     * Test for PMA_getSpecialCharsAndBackupFieldForExistingRow
     *
     * @return void
     */
    public function testGetSpecialCharsAndBackupFieldForExistingRow()
    {
        $column['Field'] = 'f';
        $current_row['f'] = null;
        $_REQUEST['default_action'] = 'insert';
        $column['Key'] = 'PRI';
        $column['Extra'] = 'fooauto_increment';

        $result = PMA_getSpecialCharsAndBackupFieldForExistingRow(
            $current_row, $column, array(), false, null, 'a'
        );

        $this->assertEquals(
            array(
                true,
                null,
                null,
                null,
                '<input type="hidden" name="fields_preva" value="" />'
            ),
            $result
        );

        // Case 2 (bit)
        unset($_REQUEST['default_action']);

        $current_row['f'] = "123";
        $extracted_columnspec['spec_in_brackets'] = 20;
        $column['True_Type'] = 'bit';

        $result = PMA_getSpecialCharsAndBackupFieldForExistingRow(
            $current_row, $column, $extracted_columnspec, false, null, 'a'
        );

        /*
        $this->assertEquals(
            array(
                false,
                "",
                "00010011001000110011",
                null,
                '<input type="hidden" name="fields_preva" value="123" />'
            ),
            $result
        );
         */
        // Case 3 (bit)
        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $GLOBALS['dbi'] = $dbi;

        $current_row['f'] = "123";
        $extracted_columnspec['spec_in_brackets'] = 20;
        $column['True_Type'] = 'int';

        $result = PMA_getSpecialCharsAndBackupFieldForExistingRow(
            $current_row, $column, $extracted_columnspec, false, array('int'), 'a'
        );

        $this->assertEquals(
            array(
                false,
                "",
                "'',",
                null,
                '<input type="hidden" name="fields_preva" value="\'\'," />'
            ),
            $result
        );

        // Case 4 (else)
        $column['is_binary'] = true;
        $GLOBALS['cfg']['ProtectBinary'] = false;
        $current_row['f'] = "11001";
        $extracted_columnspec['spec_in_brackets'] = 20;
        $column['True_Type'] = 'char';
        $_SESSION['tmpval']['display_binary_as_hex'] = true;
        $GLOBALS['cfg']['ShowFunctionFields'] = true;

        $result = PMA_getSpecialCharsAndBackupFieldForExistingRow(
            $current_row, $column, $extracted_columnspec, false, array('int'), 'a'
        );

        $this->assertEquals(
            array(
                false,
                "3131303031",
                "3131303031",
                "3131303031",
                '<input type="hidden" name="fields_preva" value="3131303031" />'
            ),
            $result
        );

        // Case 5 (false display_binary_as_hex)
        $current_row['f'] = "11001\x00";
        $_SESSION['tmpval']['display_binary_as_hex'] = false;

        $result = PMA_getSpecialCharsAndBackupFieldForExistingRow(
            $current_row, $column, $extracted_columnspec, false, array('int'), 'a'
        );

        $this->assertEquals(
            array(
                false,
                '11001\0',
                '11001\0',
                '11001\0',
                '<input type="hidden" name="fields_preva" value="11001\0" />'
            ),
            $result
        );
    }

    /**
     * Test for PMA_getSpecialCharsAndBackupFieldForInsertingMode
     *
     * @return void
     */
    public function testGetSpecialCharsAndBackupFieldForInsertingMode()
    {
        $column['True_Type'] = 'bit';
        $column['Default'] = b'101';
        $column['is_binary'] = true;
        $GLOBALS['cfg']['ProtectBinary'] = false;
        $_SESSION['tmpval']['display_binary_as_hex'] = true;
        $GLOBALS['cfg']['ShowFunctionFields'] = true;

        $result = PMA_getSpecialCharsAndBackupFieldForInsertingMode($column, false);

        $this->assertEquals(
            array(
                false,
                '101',
                '101',
                '',
                '101'
            ),
            $result
        );

        // case 2
        unset($column['Default']);
        $column['True_Type'] = 'char';

        $result = PMA_getSpecialCharsAndBackupFieldForInsertingMode($column, false);

        $this->assertEquals(
            array(
                true,
                '',
                '',
                '',
                ''
            ),
            $result
        );
    }

    /**
     * Test for PMA_getParamsForUpdateOrInsert
     *
     * @return void
     */
    public function testGetParamsForUpdateOrInsert()
    {
        $_REQUEST['where_clause'] = 'LIMIT 1';
        $_REQUEST['submit_type'] = 'showinsert';

        $result = PMA_getParamsForUpdateOrInsert();

        $this->assertEquals(
            array(
                array('LIMIT 1'),
                true,
                true,
                false
            ),
            $result
        );

        // case 2 (else)
        unset($_REQUEST['where_clause']);
        $_REQUEST['fields']['multi_edit'] = array('a' => 'b', 'c' => 'd');
        $result = PMA_getParamsForUpdateOrInsert();

        $this->assertEquals(
            array(
                array('a', 'c'),
                false,
                true,
                false
            ),
            $result
        );
    }

    /**
     * Test for PMA_isInsertRow
     *
     * @return void
     */
    public function testIsInsertRow()
    {
        $_REQUEST['insert_rows'] = 5;
        $GLOBALS['cfg']['InsertRows'] = 2;

        $scriptsMock = $this->getMockBuilder('PMA_Scripts')
            ->disableOriginalConstructor()
            ->setMethods(array('addFile'))
            ->getMock();

        $scriptsMock->expects($this->once())
            ->method('addFile');

        $headerMock = $this->getMockBuilder('PMA_Header')
            ->disableOriginalConstructor()
            ->setMethods(array('getScripts'))
            ->getMock();

        $headerMock->expects($this->once())
            ->method('getScripts')
            ->will($this->returnValue($scriptsMock));

        $responseMock = $this->getMockBuilder('PMA_Response')
            ->disableOriginalConstructor()
            ->setMethods(array('getHeader'))
            ->getMock();

        $responseMock->expects($this->once())
            ->method('getHeader')
            ->will($this->returnValue($headerMock));

        $response = new ReflectionProperty('PMA_Response', '_instance');
        $response->setAccessible(true);
        $response->setValue(null, $responseMock);

        PMA_isInsertRow();

        $this->assertEquals(5, $GLOBALS['cfg']['InsertRows']);
    }

    /**
     * Test for PMA_setSessionForEditNext
     *
     * @return void
     */
    public function testSetSessionForEditNext()
    {
        $temp = new stdClass;
        $temp->orgname = 'orgname';
        $temp->table = 'table';
        $temp->type = 'real';
        $temp->primary_key = 1;
        $meta_arr = array($temp);

        $row = array('1' => 1);
        $res = 'foobar';

        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->at(0))
            ->method('query')
            ->with('SELECT * FROM `db`.`table` WHERE `a` > 2 LIMIT 1;')
            ->will($this->returnValue($res));

        $dbi->expects($this->at(1))
            ->method('fetchRow')
            ->with($res)
            ->will($this->returnValue($row));

        $dbi->expects($this->at(2))
            ->method('getFieldsMeta')
            ->with($res)
            ->will($this->returnValue($meta_arr));

        $GLOBALS['dbi'] = $dbi;
        $GLOBALS['db'] = 'db';
        $GLOBALS['table'] = 'table';
        PMA_setSessionForEditNext('`a` = 2');

        $this->assertEquals(
            'CONCAT(`table`.`orgname`) IS NULL',
            $_SESSION['edit_next']
        );
    }

    /**
     * Test for PMA_getGotoInclude
     *
     * @return void
     */
    public function testGetGotoInclude()
    {
        $GLOBALS['goto'] = '123.php';
        $GLOBALS['table'] = '';

        $this->assertEquals(
            'db_sql.php',
            PMA_getGotoInclude('index')
        );

        $GLOBALS['table'] = 'tbl';
        $this->assertEquals(
            'tbl_sql.php',
            PMA_getGotoInclude('index')
        );

        $GLOBALS['goto'] = 'db_sql.php';

        $this->assertEquals(
            'db_sql.php',
            PMA_getGotoInclude('index')
        );

        $this->assertEquals(
            '',
            $GLOBALS['table']
        );

        $_REQUEST['after_insert'] = 'new_insert';
        $this->assertEquals(
            'tbl_change.php',
            PMA_getGotoInclude('index')
        );
    }

    /**
     * Test for PMA_getErrorUrl
     *
     * @return void
     */
    public function testGetErrorUrl()
    {
        $GLOBALS['cfg']['ServerDefault'] = 1;
        $this->assertEquals(
            'tbl_change.php?lang=en&amp;token=token',
            PMA_getErrorUrl(array())
        );

        $_REQUEST['err_url'] = 'localhost';
        $this->assertEquals(
            'localhost',
            PMA_getErrorUrl(array())
        );
    }

    /**
     * Test for PMA_buildSqlQuery
     *
     * @return void
     */
    public function testBuildSqlQuery()
    {
        $GLOBALS['db'] = 'db';
        $GLOBALS['table'] = 'table';
        $query_fields = array('a', 'b');
        $value_sets = array(1, 2);

        $this->assertEquals(
            array('INSERT IGNORE INTO `db`.`table` (a, b) VALUES (1), (2)'),
            PMA_buildSqlQuery(true, $query_fields, $value_sets)
        );

        $this->assertEquals(
            array('INSERT INTO `db`.`table` (a, b) VALUES (1), (2)'),
            PMA_buildSqlQuery(false, $query_fields, $value_sets)
        );
    }

    /**
     * Test for PMA_executeSqlQuery
     *
     * @return void
     */
    public function testExecuteSqlQuery()
    {
        $query = array('SELECT 1', 'SELECT 2');
        $GLOBALS['sql_query'] = 'SELECT';
        $GLOBALS['cfg']['IgnoreMultiSubmitErrors'] = false;
        $_REQUEST['submit_type'] = '';

        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->at(0))
            ->method('query')
            ->with('SELECT 1')
            ->will($this->returnValue(true));

        $dbi->expects($this->at(1))
            ->method('affectedRows')
            ->will($this->returnValue(2));

        $dbi->expects($this->at(2))
            ->method('insertId')
            ->will($this->returnValue(1));

        $dbi->expects($this->at(5))
            ->method('query')
            ->with('SELECT 2')
            ->will($this->returnValue(false));

        $dbi->expects($this->once())
            ->method('getError')
            ->will($this->returnValue('err'));

        $dbi->expects($this->exactly(2))
            ->method('getWarnings')
            ->will($this->returnValue(array()));

        $GLOBALS['dbi'] = $dbi;

        $result = PMA_executeSqlQuery(array(), $query);

        $this->assertEquals(
            array('sql_query' => 'SELECT'),
            $result[0]
        );

        $this->assertEquals(
            2,
            $result[1]
        );

        $this->assertInstanceOf(
            'PMA_Message',
            $result[2][0]
        );

        $msg = $result[2][0];
        $reflectionMsg = new ReflectionProperty('PMA_Message', 'params');
        $reflectionMsg->setAccessible(true);

        $this->assertEquals(
            array(2),
            $reflectionMsg->getValue($msg)
        );

        $this->assertEquals(
            array(),
            $result[3]
        );

        $this->assertEquals(
            array('err'),
            $result[4]
        );

        $this->assertEquals(
            'SELECT',
            $result[5]
        );
    }

    /**
     * Test for PMA_executeSqlQuery
     *
     * @return void
     */
    public function testExecuteSqlQueryWithTryQuery()
    {
        $query = array('SELECT 1', 'SELECT 2');
        $GLOBALS['sql_query'] = 'SELECT';
        $GLOBALS['cfg']['IgnoreMultiSubmitErrors'] = true;
        $_REQUEST['submit_type'] = '';

        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->at(0))
            ->method('tryQuery')
            ->with('SELECT 1')
            ->will($this->returnValue(true));

        $dbi->expects($this->at(1))
            ->method('affectedRows')
            ->will($this->returnValue(2));

        $dbi->expects($this->at(2))
            ->method('insertId')
            ->will($this->returnValue(1));

        $dbi->expects($this->at(5))
            ->method('tryQuery')
            ->with('SELECT 2')
            ->will($this->returnValue(false));

        $dbi->expects($this->once())
            ->method('getError')
            ->will($this->returnValue('err'));

        $dbi->expects($this->exactly(2))
            ->method('getWarnings')
            ->will($this->returnValue(array()));

        $GLOBALS['dbi'] = $dbi;

        $result = PMA_executeSqlQuery(array(), $query);

        $this->assertEquals(
            array('sql_query' => 'SELECT'),
            $result[0]
        );

        $this->assertEquals(
            2,
            $result[1]
        );

        $this->assertInstanceOf(
            'PMA_Message',
            $result[2][0]
        );

        $msg = $result[2][0];
        $reflectionMsg = new ReflectionProperty('PMA_Message', 'params');
        $reflectionMsg->setAccessible(true);

        $this->assertEquals(
            array(2),
            $reflectionMsg->getValue($msg)
        );

        $this->assertEquals(
            array(),
            $result[3]
        );

        $this->assertEquals(
            array('err'),
            $result[4]
        );

        $this->assertEquals(
            'SELECT',
            $result[5]
        );
    }

    /**
     * Test for PMA_getWarningMessages
     *
     * @return void
     */
    public function testGetWarningMessages()
    {
        $warnings = array(
            array('Level' => 1, 'Code' => 42, 'Message' => 'msg1'),
            array('Level' => 2, 'Code' => 43, 'Message' => 'msg2'),
        );

        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->once())
            ->method('getWarnings')
            ->will($this->returnValue($warnings));

        $GLOBALS['dbi'] = $dbi;

        $result = PMA_getWarningMessages();

        $this->assertEquals(
            array(
                "1: #42 msg1",
                "2: #43 msg2"
            ),
            $result
        );
    }

    /**
     * Test for PMA_getDisplayValueForForeignTableColumn
     *
     * @return void
     */
    public function testGetDisplayValueForForeignTableColumn()
    {
        $map['f']['foreign_db'] = 'information_schema';
        $map['f']['foreign_table'] = 'TABLES';
        $map['f']['foreign_field'] = 'f';

        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->once())
            ->method('tryQuery')
            ->with(
                'SELECT `TABLE_COMMENT` FROM `information_schema`.`TABLES` WHERE '
                . '`f`=1',
                null,
                PMA_DatabaseInterface::QUERY_STORE
            )
            ->will($this->returnValue('r1'));

        $dbi->expects($this->once())
            ->method('numRows')
            ->with('r1')
            ->will($this->returnValue('2'));

        $dbi->expects($this->once())
            ->method('fetchRow')
            ->with('r1', 0)
            ->will($this->returnValue(array('2')));

        $GLOBALS['dbi'] = $dbi;

        $result = PMA_getDisplayValueForForeignTableColumn("=1", null, $map, 'f');

        $this->assertEquals(2, $result);
    }

    /**
     * Test for PMA_getLinkForRelationalDisplayField
     *
     * @return void
     */
    public function testGetLinkForRelationalDisplayField()
    {
        $GLOBALS['cfg']['ServerDefault'] = 1;
        $_SESSION['tmpval']['relational_display'] = 'K';
        $map['f']['foreign_db'] = 'information_schema';
        $map['f']['foreign_table'] = 'TABLES';
        $map['f']['foreign_field'] = 'f';

        $result = PMA_getLinkForRelationalDisplayField($map, 'f', "=1", "a>", "b<");

        $this->assertEquals(
            '<a href="sql.php?db=information_schema&amp;table=TABLES&amp;pos=0&amp;'
            . 'sql_query=SELECT+%2A+FROM+%60information_schema%60.%60TABLES%60+WHERE'
            . '+%60f%60%3D1&amp;lang=en&amp;token=token" title="a&gt;">b&lt;</a>',
            $result
        );

        $_SESSION['tmpval']['relational_display'] = 'D';
        $result = PMA_getLinkForRelationalDisplayField($map, 'f', "=1", "a>", "b<");

        $this->assertEquals(
            '<a href="sql.php?db=information_schema&amp;table=TABLES&amp;pos=0&amp;'
            . 'sql_query=SELECT+%2A+FROM+%60information_schema%60.%60TABLES%60+WHERE'
            . '+%60f%60%3D1&amp;lang=en&amp;token=token" title="b&lt;">a&gt;</a>',
            $result
        );
    }

    /**
     * Test for PMA_transformEditedValues
     *
     * @return void
     */
    public function testTransformEditedValues()
    {
        $edited_values = array(
            array('c' => 'cname')
        );
        $GLOBALS['cfg']['ServerDefault'] = 1;
        $_REQUEST['where_clause'] = 1;
        $transformation['transformation_options'] = "'option ,, quoted',abd";
        $result = PMA_transformEditedValues(
            'db', 'table', $transformation, $edited_values,
            'Text_Plain_Append.class.php', 'c', array('a' => 'b')
        );

        $this->assertEquals(
            array('a' => 'b', 'transformations' => array("cnameoption ,, quoted")),
            $result
        );
    }

    /**
     * Test for PMA_getQueryValuesForInsertAndUpdateInMultipleEdit
     *
     * @return void
     */
    public function testGetQueryValuesForInsertAndUpdateInMultipleEdit()
    {
        $multi_edit_columns_name[0] = 'fld';

        $result = PMA_getQueryValuesForInsertAndUpdateInMultipleEdit(
            $multi_edit_columns_name, null, null, null, null, true, array(1),
            array(2), 'foo', array(), 0, null
        );

        $this->assertEquals(
            array(
                array(1, 'foo'),
                array(2, '`fld`')
            ),
            $result
        );

        $result = PMA_getQueryValuesForInsertAndUpdateInMultipleEdit(
            $multi_edit_columns_name, array(), null, null, null, false, array(1),
            array(2), 'foo', array(), 0, array('a')
        );

        $this->assertEquals(
            array(
                array(1, '`fld` = foo'),
                array(2)
            ),
            $result
        );

        $result = PMA_getQueryValuesForInsertAndUpdateInMultipleEdit(
            $multi_edit_columns_name, array('b'), "'`c`'", array('c'), array(null),
            false, array(1), array(2), 'foo', array(), 0, array('a')
        );

        $this->assertEquals(
            array(
                array(1),
                array(2)
            ),
            $result
        );

        $result = PMA_getQueryValuesForInsertAndUpdateInMultipleEdit(
            $multi_edit_columns_name, array('b'), "'`c`'", array('c'), array(3),
            false, array(1), array(2), 'foo', array(), 0, array(null)
        );

        $this->assertEquals(
            array(
                array(1, '`fld` = foo'),
                array(2)
            ),
            $result
        );
    }

    /**
     * Test for PMA_getCurrentValueAsAnArrayForMultipleEdit
     *
     * @return void
     */
    public function testGetCurrentValueAsAnArrayForMultipleEdit()
    {
        $multi_edit_funcs = array(null);

        $result = PMA_getCurrentValueAsAnArrayForMultipleEdit(
            null, null, $multi_edit_funcs, null, null, 'currVal', null,
            null, null, 0
        );

        $this->assertEquals('currVal', $result);

        // case 2
        $multi_edit_funcs = array('UUID');

        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->once())
            ->method('fetchValue')
            ->with('SELECT UUID()')
            ->will($this->returnValue('uuid1234'));

        $GLOBALS['dbi'] = $dbi;

        $result = PMA_getCurrentValueAsAnArrayForMultipleEdit(
            null, null, $multi_edit_funcs, null, null, 'currVal', null,
            null, null, 0
        );

        $this->assertEquals("'uuid1234'", $result);

        // case 3
        $multi_edit_funcs = array('AES_ENCRYPT');
        $multi_edit_salt = array("");
        $result = PMA_getCurrentValueAsAnArrayForMultipleEdit(
            null, null, $multi_edit_funcs, $multi_edit_salt, array(), "'''", array(),
            array('func'), array('func'), 0
        );
        $this->assertEquals("AES_ENCRYPT(''','')", $result);

        // case 4
        $multi_edit_funcs = array('func');
        $multi_edit_salt = array();
        $result = PMA_getCurrentValueAsAnArrayForMultipleEdit(
            null, null, $multi_edit_funcs, $multi_edit_salt, array(), "'''", array(),
            array('func'), array('func'), 0
        );
        $this->assertEquals("func(''')", $result);

        // case 5
        $result = PMA_getCurrentValueAsAnArrayForMultipleEdit(
            null, null, $multi_edit_funcs, $multi_edit_salt, array(), "''", array(),
            array('func'), array('func'), 0
        );
        $this->assertEquals("func()", $result);
    }

    /**
     * Test for PMA_getCurrentValueForDifferentTypes
     *
     * @return void
     */
    public function testGetCurrentValueForDifferentTypes()
    {
        $prow['a'] = b'101';

        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->at(4))
            ->method('fetchSingleRow')
            ->with('SELECT * FROM `table` WHERE 1;')
            ->will($this->returnValue($prow));

        $GLOBALS['dbi'] = $dbi;

        $result = PMA_getCurrentValueForDifferentTypes(
            '123', 0, array(), null, null, null, null, null, null, true, true,
            '1', 'table'
        );

        $this->assertEquals(
            '123',
            $result
        );

        // case 2
        $result = PMA_getCurrentValueForDifferentTypes(
            false, 0, array('test'), '', array(1), null, null, null,
            null, true, true, '1', 'table'
        );

        $this->assertEquals(
            'NULL',
            $result
        );

        // case 3
        $result = PMA_getCurrentValueForDifferentTypes(
            false, 0, array('test'), '', array(), null, null, null,
            null, true, true, '1', 'table'
        );

        $this->assertEquals(
            "''",
            $result
        );

        // case 4
        $_REQUEST['fields']['multi_edit'][0][0] = array();
        $result = PMA_getCurrentValueForDifferentTypes(
            false, 0, array('set'), '', array(), 0, null, null,
            null, true, true, '1', 'table'
        );

        $this->assertEquals(
            "''",
            $result
        );

        // case 5
        $result = PMA_getCurrentValueForDifferentTypes(
            false, 0, array('protected'), '', array(), 0, array('a'), null,
            null, true, true, '1', 'table'
        );

        $this->assertEquals(
            "0x313031",
            $result
        );

        // case 6
        $result = PMA_getCurrentValueForDifferentTypes(
            false, 0, array('protected'), '', array(), 0, array('a'), null,
            null, true, true, '1', 'table'
        );

        $this->assertEquals(
            "",
            $result
        );

        // case 7
        $result = PMA_getCurrentValueForDifferentTypes(
            false, 0, array('bit'), '20\'12', array(), 0, array('a'), null,
            null, true, true, '1', 'table'
        );

        $this->assertEquals(
            "b'00010'",
            $result
        );

        // case 7
        $result = PMA_getCurrentValueForDifferentTypes(
            false, 0, array('date'), '20\'12', array(), 0, array('a'), null,
            null, true, true, '1', 'table'
        );

        $this->assertEquals(
            "'20''12'",
            $result
        );

        // case 8
        $_REQUEST['fields']['multi_edit'][0][0] = array();
        $result = PMA_getCurrentValueForDifferentTypes(
            false, 0, array('set'), '', array(), 0, null, array(1),
            null, true, true, '1', 'table'
        );

        $this->assertEquals(
            "NULL",
            $result
        );

        // case 9
        $result = PMA_getCurrentValueForDifferentTypes(
            false, 0, array('protected'), '', array(), 0, array('a'), array(null),
            array(1), true, true, '1', 'table'
        );

        $this->assertEquals(
            "''",
            $result
        );
    }

    /**
     * Test for PMA_verifyWhetherValueCanBeTruncatedAndAppendExtraData
     *
     * @return void
     */
    public function testVerifyWhetherValueCanBeTruncatedAndAppendExtraData()
    {
        $extra_data = array('isNeedToRecheck' => true);
        $meta = new stdClass();
        $_REQUEST['where_clause'][0] = 1;

        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->at(0))
            ->method('tryQuery')
            ->with('SELECT `table`.`a` FROM `db`.`table` WHERE 1');

        $meta->type = 'int';
        $dbi->expects($this->at(1))
            ->method('getFieldsMeta')
            ->will($this->returnValue(array($meta)));

        $dbi->expects($this->at(2))
            ->method('fetchValue')
            ->will($this->returnValue(false));

        $dbi->expects($this->at(3))
            ->method('tryQuery')
            ->with('SELECT `table`.`a` FROM `db`.`table` WHERE 1');

        $meta->type = 'int';
        $dbi->expects($this->at(4))
            ->method('getFieldsMeta')
            ->will($this->returnValue(array($meta)));

        $dbi->expects($this->at(5))
            ->method('fetchValue')
            ->will($this->returnValue('123'));

        $dbi->expects($this->at(6))
            ->method('tryQuery')
            ->with('SELECT `table`.`a` FROM `db`.`table` WHERE 1');

        $meta->type = 'timestamp';
        $dbi->expects($this->at(7))
            ->method('getFieldsMeta')
            ->will($this->returnValue(array($meta)));

        $dbi->expects($this->at(8))
            ->method('fetchValue')
            ->will($this->returnValue('2013-08-28 06:34:14'));

        $GLOBALS['dbi'] = $dbi;

        PMA_verifyWhetherValueCanBeTruncatedAndAppendExtraData(
            'db', 'table', 'a', $extra_data
        );

        $this->assertFalse($extra_data['isNeedToRecheck']);

        PMA_verifyWhetherValueCanBeTruncatedAndAppendExtraData(
            'db', 'table', 'a', $extra_data
        );

        $this->assertEquals('123', $extra_data['truncatableFieldValue']);
        $this->assertTrue($extra_data['isNeedToRecheck']);

        PMA_verifyWhetherValueCanBeTruncatedAndAppendExtraData(
            'db', 'table', 'a', $extra_data
        );

        $this->assertEquals(
            '2013-08-28 06:34:14.000000', $extra_data['truncatableFieldValue']
        );
        $this->assertTrue($extra_data['isNeedToRecheck']);
    }

    /**
     * Test for PMA_getTableColumns
     *
     * @return void
     */
    public function testGetTableColumns()
    {
        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->at(0))
            ->method('selectDb')
            ->with('db');

        $dbi->expects($this->at(1))
            ->method('getColumns')
            ->with('db', 'table')
            ->will($this->returnValue(array('a' => 'b', 'c' => 'd')));

        $GLOBALS['dbi'] = $dbi;

        $result = PMA_getTableColumns('db', 'table');

        $this->assertEquals(
            array('b', 'd'),
            $result
        );
    }

    /**
     * Test for PMA_determineInsertOrEdit
     *
     * @return void
     */
    public function testDetermineInsertOrEdit()
    {
        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $GLOBALS['dbi'] = $dbi;
        $_REQUEST['where_clause'] = '1';
        $_SESSION['edit_next'] = '1';
        $_REQUEST['ShowFunctionFields'] = true;
        $_REQUEST['ShowFieldTypesInDataEditView'] = true;
        $_REQUEST['after_insert'] = 'edit_next';
        $GLOBALS['cfg']['InsertRows'] = 2;
        $GLOBALS['cfg']['ShowSQL'] = false;
        $_REQUEST['default_action'] = 'insert';

        $responseMock = $this->getMockBuilder('PMA_Response')
            ->disableOriginalConstructor()
            ->setMethods(array('addHtml'))
            ->getMock();

        $response = new ReflectionProperty('PMA_Response', '_instance');
        $response->setAccessible(true);
        $response->setValue(null, $responseMock);

        $result = PMA_determineInsertOrEdit('1', 'db', 'table');

        $this->assertEquals(
            array(
                false,
                null,
                array(1),
                null,
                array(null),
                array(null),
                false,
                "edit_next"
            ),
            $result
        );

        // case 2
        unset($_REQUEST['where_clase']);
        unset($_SESSION['edit_next']);
        $_REQUEST['default_action'] = '';

        $result = PMA_determineInsertOrEdit(null, 'db', 'table');

        $this->assertEquals(
            array(
                false,
                '1',
                array(1),
                array(1),
                array(null),
                array(null),
                false,
                "edit_next"
            ),
            $result
        );
    }

    /**
     * Test for PMA_getCommentsMap
     *
     * @return void
     */
    public function testGetCommentsMap()
    {
        $GLOBALS['cfg']['ShowPropertyComments'] = false;

        $dbi = $this->getMockBuilder('PMA_DatabaseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->once())
            ->method('getColumns')
            ->with('db', 'table', null, true)
            ->will(
                $this->returnValue(
                    array(array('Comment' => 'b', 'Field' => 'd'))
                )
            );

        $GLOBALS['dbi'] = $dbi;

        $this->assertEquals(
            array(),
            PMA_getCommentsMap('db', 'table')
        );

        $GLOBALS['cfg']['ShowPropertyComments'] = true;

        $this->assertEquals(
            array('d' => 'b'),
            PMA_getCommentsMap('db', 'table')
        );
    }

    /**
     * Test for PMA_getUrlParameters
     *
     * @return void
     */
    public function testGetUrlParameters()
    {
        $_REQUEST['sql_query'] = 'SELECT';
        $GLOBALS['goto'] = 'tbl_change.php';

        $this->assertEquals(
            array(
                'db' => 'foo',
                'sql_query' => 'SELECT',
                'table' => 'bar'
            ),
            PMA_getUrlParameters('foo', 'bar')
        );
    }
}
?>
