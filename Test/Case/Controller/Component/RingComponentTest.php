<?php
App::uses('Controller', 'Controller');
App::uses('ImageRingComponent', 'Imagebinder.Controller/Component');
session_start(); // http://mindthecode.com/using-sessions-in-phpunit-tests-with-cakephp/
class ImagebinderPost extends CakeTestModel{

    public $name = 'ImagebinderPost';

    public $actsAs = array('Imagebinder.ImageBindable');
}

class ImagebinderPostsTestController extends Controller{

    public $name = 'ImagebinderPostsTest';

    public $uses = array('ImagebinderPost');

    public $components = array('Session', 'Imagebinder.ImageRing');

    /**
     * redirect
     *
     * @param $url, $status = null, $exit = true
     * @return
     */
    public function redirect($url, $status = null, $exit = true){
        $this->redirectUrl = $url;
    }
}

class RingComponentTest extends CakeTestCase{

    public $fixtures = array('plugin.filebinder.attachment',
                             'plugin.filebinder.filebinder_post');

    public function setUp() {
        parent::setUp();
        $this->Controller = new ImagebinderPostsTestController();
        $this->Controller->constructClasses();
    }

    public function tearDown() {
        $this->Controller->Session->delete('filebinder');
        unset($this->Controller);
        ClassRegistry::flush();
    }

    /**
     * testStartup
     *
     * en:
     * jpn: Ring::startup()後にはhelperにImagebinder.Labelが設定される
     */
    public function testStartup() {
        $this->Controller->Components->init($this->Controller);
        $this->initialized = true;
        $this->Controller->ImageRing->startup($this->Controller);
        $this->assertTrue(in_array('Imagebinder.ImageLabel', $this->Controller->helpers));
    }

    /**
     * testBindUp
     *
     * en:
     * jpn: $this->dataが存在する場合にRing::bindUp()を実行するとアップロードされたファイル情報が整形される
     */
    public function testBindUp(){
        $tmpPath = TMP . 'tests' . DS . 'bindup.png';

        // set test.png
        $this->_setTestFile($tmpPath);

        $this->Controller->ImagebinderPost->bindFields = array(
                                                              array('field' => 'logo',
                                                                    'tmpPath'  => CACHE,
                                                                    'filePath' => TMP . 'tests' . DS,
                                                                    ),
                                                              );

        $this->Controller->request = new CakeRequest(null, false);
        $this->Controller->request->data = array('ImagebinderPost' => array());
        $this->Controller->request->data['ImagebinderPost']['title'] = 'Title';
        $this->Controller->request->data['ImagebinderPost']['logo'] = array('name' => 'logo.png',
                                                                           'tmp_name' => $tmpPath,
                                                                           'type' => 'image/png',
                                                                           'size' => 100,
                                                                           'error' => 0);

        $this->Controller->Components->init($this->Controller);
        $this->initialized = true;
        $this->Controller->beforeFilter();
        $this->Controller->ImageRing->bindUp();
        $this->assertIdentical($this->Controller->request->data['ImagebinderPost']['logo']['model'], 'ImagebinderPost');
    }

    /**
     * testBindUpInvalidUploadedFile
     *
     * en: test Ring::_checkFileUploaded
     * jpn: $this->dataのファイルアップロードの値(キー)が不正な場合は該当フィールドの値にnullがセットされる
     */
    public function testBindUpInvalidUploadedFile(){
        $tmpPath = TMP . 'tests' . DS . 'bindup.png';

        // set test.png
        $this->_setTestFile($tmpPath);

        $this->Controller->ImagebinderPost->bindFields = array(
                                                              array('field' => 'logo',
                                                                    'tmpPath'  => CACHE,
                                                                    'filePath' => TMP . 'tests' . DS,
                                                                    ),
                                                              );

        $this->Controller->request = new CakeRequest(null, false);
        $this->Controller->request->data = array('ImagebinderPost' => array());
        $this->Controller->request->data['ImagebinderPost']['title'] = 'Title';
        $this->Controller->request->data['ImagebinderPost']['logo'] = array('name' => 'logo.png',
                                                                           'tmp_name' => $tmpPath,
                                                                           'invalid_key' => 'invalid', // invalid field
                                                                           'size' => 100,
                                                                           'error' => 0);

        $this->Controller->Components->init($this->Controller);
        $this->initialized = true;
        $this->Controller->beforeFilter();

        $this->Controller->ImageRing->bindUp();

        $expected = array('ImagebinderPost' => array('title' => 'Title',
                                                    'logo' => null));

        $this->assertIdentical($this->Controller->request->data, $expected);
    }

    /**
     * testBindUp_move_uploaded_file
     *
     * en:
     * jpn: テストケースで生成した$this->dataはダミーなのでmove_uploaded_file()はfalseなのでtmp_bind_pathにファイルは生成されない
     */
    public function testBindUp_move_uploaded_file(){
        $tmpPath = TMP . 'tests' . DS . 'bindup.png';

        // set test.png
        $this->_setTestFile($tmpPath);

        $this->Controller->ImagebinderPost->bindFields = array(
                                                              array('field' => 'logo',
                                                                    'tmpPath'  => CACHE,
                                                                    'filePath' => TMP . 'tests' . DS,
                                                                    ),
                                                              );
        $this->Controller->request = new CakeRequest(null, false);
        $this->Controller->request->data = array('ImagebinderPost' => array());
        $this->Controller->request->data['ImagebinderPost']['title'] = 'Title';
        $this->Controller->request->data['ImagebinderPost']['logo'] = array('name' => 'logo.png',
                                                                           'tmp_name' => $tmpPath,
                                                                           'type' => 'image/png',
                                                                           'size' => 100,
                                                                           'error' => 0);

        $this->Controller->Components->init($this->Controller);
        $this->initialized = true;
        $this->Controller->beforeFilter();

        $this->Controller->ImageRing->bindUp();

        // test.png is not uploaded file.
        $this->assertIdentical(file_exists($this->Controller->request->data['ImagebinderPost']['logo']['tmp_bind_path']), false);
    }

    /**
     * test_bindDown
     *
     * en:
     * jpn: Ring::bindDown()を実行するとアップロードファイル情報がSessionに保持される
     */
    public function test_bindDown(){
        $tmpPath = TMP . 'tests' . DS . 'binddown.png';

        // set test.png
        $this->_setTestFile($tmpPath);

        $this->Controller->ImagebinderPost->bindFields = array(
                                                              array('field' => 'logo',
                                                                    'tmpPath'  => CACHE,
                                                                    'filePath' => TMP . 'tests' . DS,
                                                                    ),
                                                              );

        $this->Controller->request = new CakeRequest(null, false);
        $this->Controller->request->data = array('ImagebinderPost' => array());
        $this->Controller->request->data['ImagebinderPost']['title'] = 'Title';
        $this->Controller->request->data['ImagebinderPost']['logo'] = array('name' => 'logo.png',
                                                                           'tmp_name' => $tmpPath,
                                                                           'type' => 'image/png',
                                                                           'size' => 100,
                                                                           'error' => 0);

        $this->Controller->Components->init($this->Controller);
        $this->initialized = true;
        $this->Controller->beforeFilter();

        $this->Controller->ImageRing->bindUp();
        $this->Controller->ImageRing->bindDown();

        $expected = $this->Controller->request->data['ImagebinderPost']['logo'];
        $this->assertIdentical($this->Controller->Session->read('Filebinder.ImagebinderPost.logo'), $expected);
    }

    /**
     * _setTestFile
     *
     * @return Boolean
     */
    private function _setTestFile($to = null){
        if (!$to) {
            return false;
        }
        $from = dirname(__FILE__) . '/../../../../Test/File/test.png';
        return copy($from, $to);
    }
}