<?php

class Tilda_Admin
{

    private static $initiated = false;
    private static $libs = array('curl_init','timezonedb');
    private static $log_time = null;
    public static $ts_start_plugin = null;
    public static $global_message='';
    
    public static function init()
    {
        if (!self::$initiated) {
            self::init_hooks();
        }
    }

    public static function init_hooks()
    {
        if (!self::$ts_start_plugin) {
            self::$ts_start_plugin = time();
        }

        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);

        self::$initiated = true;

        add_action('admin_init', array('Tilda_Admin', 'admin_init'));
        add_action('admin_menu', array('Tilda_Admin', 'admin_menu'), 5);
        add_action('add_meta_boxes', array('Tilda_Admin', 'add_meta_box'),5);
        add_action('admin_enqueue_scripts', array('Tilda_Admin', 'admin_enqueue_scripts'));
        add_action('save_post', array('Tilda_Admin', 'save_tilda_data'), 10);

        add_action('edit_form_after_title', function () {
            global $post, $wp_meta_boxes;
            do_meta_boxes(get_current_screen(), 'advanced', $post);
            unset($wp_meta_boxes[get_post_type($post)]['advanced']);
        });

        add_action("wp_ajax_tilda_admin_sync", array("Tilda_Admin", "ajax_sync"));
        add_action("wp_ajax_tilda_admin_export_file", array("Tilda_Admin", "ajax_export_file"));
        add_action("wp_ajax_tilda_admin_switcher_status", array("Tilda_Admin", "ajax_switcher_status"));
        
        
    }

    public static function admin_init()
    {
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);
        register_setting(
            'tilda_options',
            'tilda_options',
            array('Tilda_Admin', 'options_validate')
        );

        add_settings_section(
            'tilda_keys',
            '',
            false,
            'tilda-config'
        );

        add_settings_field(
            'tilda_public_key',
            'Public key',
            array('Tilda_Admin', 'public_key_field'),
            'tilda-config',
            'tilda_keys'
        );

        add_settings_field(
            'tilda_secret_key',
            'Secret key',
            array('Tilda_Admin', 'secret_key_field'),
            'tilda-config',
            'tilda_keys'
        );
    }

    public static function admin_menu()
    {
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);
        self::load_menu();
    }

    public static function load_menu()
    {
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);
        add_submenu_page(
            'options-general.php',
            'Tilda Publishing',
            'Tilda Publishing',
            'manage_options',
            'tilda-config',
            array('Tilda_Admin', 'display_configuration_page')
        );
    }

    public static function add_meta_box()
    {
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);

        global $post;
        $data = get_post_meta($post->ID, '_tilda', true);
        $screens = array('post', 'page');

        
        foreach ($screens as $screen) {
            
            if (!isset($data["status"]) || $data["status"] != 'on') {
                add_meta_box(
                    'tilda_switcher',
                    'Tilda Publishing',
                    array('Tilda_Admin', 'switcher_callback'),
                    $screen
                );
            };
            if (isset($data["status"]) && $data["status"] == 'on') {
                add_meta_box(
                    'tilda_pages_list',
                    'Tilda Publishing',
                    array('Tilda_Admin', 'pages_list_callback'),
                    $screen,
                    'advanced',
                    'high'
                );
            };
        }
    }

    public static function pages_list_callback($post)
    {
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);

        $data = get_post_meta($post->ID, '_tilda', true);
        $page_id = isset($data["page_id"]) ? $data["page_id"] : false;
        $project_id = isset($data["project_id"]) ? $data["project_id"] : false;

        if (isset($data['update_data']) && $data['update_data'] == 'update_data') {
            /* обновляем список проектов и страниц */
            self::initialize();
            unset($data['update_data']);
            update_post_meta($post->ID, '_tilda', $data);
        }

        $projects_list = Tilda::get_local_projects();
        if (!$projects_list){
            Tilda::$errors->add( 'refresh',__('Refresh pages list','tilda'));
        }
        
        self::view(
            'pages_meta_box',
            array('projects_list' => $projects_list, 'data' => $data)
        );

    }
    public static function save_tilda_data($postID)
    {
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);

        if (!isset($_POST['tilda'])) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($postID)) {
            return;
        }

        check_admin_referer("tilda_switcher", "tilda_nonce");

        $data = get_post_meta($postID, '_tilda', true);
        foreach($_POST['tilda'] as $key => $val) {
            $data[$key] = $val;
        }

        update_post_meta($postID, '_tilda', $data);

    }

    public static function admin_enqueue_scripts($hook)
    {
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);

        if ('post.php' != $hook && 'post-new.php' != $hook) {
            return;
        }

        wp_enqueue_script('tilda_js', TILDA_PLUGIN_URL . 'js/plugin.js', array('jquery','jquery-ui-tabs'));

        wp_enqueue_style('jquery-ui-tabs', TILDA_PLUGIN_URL . 'css/jquery-ui-tabs.css');
        wp_enqueue_style('tilda_css', TILDA_PLUGIN_URL . 'css/styles.css');
    }

    public static function initialize()
    {
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);

        $projects = Tilda::get_projects();
        $projects_list = array();
        if (is_wp_error($projects)){
            return;
        }

        if (!$projects || count($projects) <= 0) {
            Tilda::$errors->add( 'empty_project_list',__('Projects list is empty','tilda'));
            return;
        }

        foreach ($projects as $project) {
            $project = Tilda::get_projectexport($project->id);

            if ($project) {
                $id = $project->id;

                $projects_list[$id] = $project;

                // self::download_project_assets($project);

                $pages = Tilda::get_pageslist($id);
                if ($pages && count($pages) > 0) {
                    $projects_list[$id]->pages = array();
                    foreach ($pages as $page) {
                        $projects_list[$id]->pages[$page->id] = $page;
                    }
                }
            }
        }

        update_option('tilda_projects', $projects_list);
    }

    public static function get_page($page_id, $project_id)
    {
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);

        $projects = Tilda::get_local_projects();
        $page = $projects[$project_id]->pages[$page_id];

        return $page;
    }

    public static function set_page($page, $project_id, $post_id=0){
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);

        $projects = Tilda::get_local_projects();
        if (isset($page['html'])) {
            unset($page['html']);
        }
        if ($post_id > 0) {
            $page['post_id'] = $post_id;
        }
        $projects[$project_id]->pages[$page->id] = $page;
        update_option('tilda_projects', $projects);
    }

    private static function scandir($dir)
    {
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);

        $list = scandir($dir);
        return array_values($list);
    }

    private static function clear_dir($dir)
    {
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);

        $list = self::scandir($dir);

        foreach ($list as $file) {
            if ($file != '.' && $file != '..') {
                if (is_dir($dir . $file)) {
                    self::clear_dir($dir . $file . '/');
                    rmdir($dir . $file);
                } else {
                    unlink($dir . $file);
                }
            }
        }
    }

    public static function public_key_field()
    {

        $options = get_option('tilda_options');
        $key = (isset($options['public_key'])) ? $options['public_key'] : '';
        ?>
        <input type="text" id="public_key" name="tilda_options[public_key]" maxlength="100" size="50"
               value="<?= esc_attr($key); ?>"/>
<?php
    }

    public static function secret_key_field()
    {
        $options = get_option('tilda_options');
        $key = (isset($options['secret_key'])) ? $options['secret_key'] : '';
        ?>
        <input type="text" id="secret_key" name="tilda_options[secret_key]" maxlength="100" size="50"
               value="<?= esc_attr($key); ?>"/>
<?php
    }

    public static function options_validate($input)
    {
        return $input;
    }

    private static function validate_required_libs(){
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);

        $libs = self::$libs;
        foreach ($libs as $lib_name){
            if(!extension_loaded($lib_name)){
                Tilda::$errors->add( 'no_library',__('Not found library ','tilda').$lib_name);
            }
        }


    }

    public static function display_configuration_page()
    {
//        self::validate_required_libs();

        self::view('configuration');
    }

    public static function switcher_callback($post)
    {
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);

        $data = get_post_meta($post->ID, '_tilda', true);

        if (!Tilda::verify_access()){
            Tilda::$errors->add( 'empty_keys',__('The security keys is not set','tilda'));
        }

        self::view('switcher_status', array('data' => $data));
    }

    public static function view($name, array $args = array())
    {
        // Tilda_Admin::log(__CLASS__."::".__FUNCTION__, __FILE__, __LINE__);

        $args = apply_filters('tilda_view_arguments', $args, $name);

        foreach ($args AS $key => $val) {
            $$key = $val;
        }

        $file = TILDA_PLUGIN_DIR . 'views/' . $name . '.php';
        include($file);
    }
    
    static public function log($message, $file=__FILE__, $line=__LINE__)
    {
        if (self::$log_time === null) {
            self::$log_time = date('Y-m-d H:i:s');
        }
        if (!self::$ts_start_plugin) {
            self::$ts_start_plugin = time();
        }
       $sec = time() - self::$ts_start_plugin;
        $f = fopen(Tilda::get_upload_dir() . '/log.txt','a');
        fwrite($f, "[".self::$log_time." - $sec s] ".$message." in [file: $file, line: $line]\n");
        fclose($f);
    }
   
    /**
     * Метод запрашивает данные указанного проекта с Тильды, включая страницы проекта, и сохраняет эти данные в опции tilda_projects
     * @param int $project_id - код проекта в Тильде
     * @return stdClass $project обновленные данные по проекту
     */
    public static function update_project($project_id)
    {
        $project = Tilda::get_projectexport($project_id);
        $projects = Tilda::get_local_projects();
        
        $pages = Tilda::get_pageslist($project_id);
        if ($pages && count($pages) > 0) {
            $project->pages = array();
            foreach ($pages as $page) {
                $project->pages[$page->id] = $page;
            }
        }

        $projects[$project_id] = $project;

        $upload_dir = Tilda::get_upload_dir() . $project->id . '/';

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755);
        }

        // self::clear_dir($upload_dir);

        $css_path = $upload_dir . 'css/';
        $js_path = $upload_dir . 'js/';
        $pages_path = $upload_dir . 'pages/';

        if (!is_dir($css_path)) {
            mkdir($css_path, 0755);
        }
        if (!is_dir($js_path)) {
            mkdir($js_path, 0755);
        }
        if (!is_dir($pages_path)) {
            mkdir($pages_path, 0755);
        }

        update_option('tilda_projects', $projects);
        return $project;
    }
    
    /**
     * Обновляем информацию о соответствии page_id в post_id
     * Нужно для реализации механизма обновления по расписанию
     * 
     * @param $page_id код страницы в Тильде
     * @param $post_id код страницы или поста в вордпрессе
     * @return array массив связи
     */
    public static function update_maps($page_id, $post_id)
    {
        $maps = Tilda::get_map_pages();
        if(! $maps) {
            $maps = array();
        }
        $maps[$page_id] = $post_id;
        
        update_option('tilda_map_pages', $maps);
        return $maps;
    }
    
    public static function replace_outer_image_to_local($tildapage, $export_imgpath='')
    {
        $exportimages = array();
        $replaceimages = array();
        $upload_path = Tilda::get_upload_path() . $tildapage->projectid . '/pages/'.$tildapage->id.'/';
        
        $uniq = array();
        
        foreach($tildapage->images as $image) {
            if( isset($uniq[$image->from]) ){ continue; }
            $uniq[$image->from] = 1;
            
            if ($export_imgpath > '') {
                $exportimages[] = '|'.$export_imgpath.'/'.$image->to.'|i';
            } else {
                $exportimages[] = '|'.$image->to.'|i';
            }
            $replaceimages[] = $upload_path.$image->to;
        }
        $html = preg_replace($exportimages, $replaceimages, $tildapage->html);
        if ($html) {
            $tildapage->html = $html;
        }
        return $tildapage;
    }
    
    /**
     * экспортирует HTML и список используемых файлов (картинок, стилей и скриптов) из Тильды
     * @param integer $page_id код страницы в Тильде
     * @param integer $project_id код проекта в Тильде
     * @param integer $post_id код страницы или поста на Вордпрессе
     *
     * @return array $arDownload - список файлов для закачки (откуда и куда сохранить, в случае ошибки возвращает WP_Error)
     */
    public static function export_tilda_page($page_id, $project_id, $post_id)
    {
        $project = Tilda::get_local_project($project_id);

        /* если проекта нет, или у него устаревший формат, то запросим его с Тильды */
        if (
            ! $project
            || !isset($project->css)
            || !isset($project->css[0])
            || !isset($project->css[0]->to)
        ) {
            $project = self::update_project($project_id);
        }
        
        if (is_wp_error($project)) {
            return $project;
            //$arResult['error'] = __("Error. Can't find project with this 'projectid' parameter");
            //echo json_encode($arResult);
            //wp_die();
        }

        $tildapage = Tilda::get_pageexport($page_id);

        if (is_wp_error($tildapage)) {
            return $tildapage;
            //$arResult['error'] = __("Error. Can't find page with this 'pageid' parameter");
            //echo json_encode($arResult);
            //wp_die();
        }

        $tildapage->html = htmlspecialchars_decode($tildapage->html);

        self::update_maps($page_id, $post_id);
        $tildapage = Tilda_Admin::replace_outer_image_to_local($tildapage, $project->export_imgpath);

        $meta = get_post_meta($post_id, '_tilda', true);
        
        $meta['export_imgpath'] = $project->export_imgpath;
        $meta['export_csspath'] = $project->export_csspath;
        $meta['export_jspath'] = $project->export_jspath;
        
        $meta['page_id'] = $tildapage->id;
        $meta['project_id'] = $tildapage->projectid;
        $meta['post_id'] = $post_id;
        
        $arDownload = array();
        
        $tildapage->css = array();
        $upload_path = Tilda::get_upload_path() . $project->id . '/';
        $upload_dir = Tilda::get_upload_dir() . $project->id . '/';
        if(! is_dir($upload_dir)) {
            mkdir($upload_dir, 0755);
        }
        if(! is_dir($upload_dir.'pages/')) {
            mkdir($upload_dir.'pages/', 0755);
        }
        if(! is_dir($upload_dir.'css/')) {
            mkdir($upload_dir.'css/', 0755);
        }
        if(! is_dir($upload_dir.'js/')) {
            mkdir($upload_dir.'js/', 0755);
        }
        
        foreach($project->css as $file) {
            $tildapage->css[] = $upload_path.'css/'.$file->to;
            $arDownload[] = array(
                'from_url' => $file->from,
                'to_dir' => $upload_dir.'css/'.$file->to
            );
        }
        
        $tildapage->js = array();
        foreach($project->js as $file) {
            $tildapage->js[] = $upload_path.'js/'.$file->to;

            $arDownload[] = array(
                'from_url' => $file->from,
                'to_dir' => $upload_dir.'js/'.$file->to
            );
        }

        $post = get_post($post_id);
        $post->post_content = 'Страница синхронизирована с Tilda.cc и вносить правки нужно там'; //$tildapage->html;
        wp_update_post( $post );

        $tildapage->sync_time = current_time('mysql');
        $meta['current_page'] = $tildapage;
        //unset($meta['current_page']->html);
        update_post_meta($post_id, '_tilda', $meta);
        


        $upload_dir = Tilda::get_upload_dir() . $project->id . '/pages/'.$tildapage->id.'/';
        if(! is_dir($upload_dir)) {
            mkdir($upload_dir, 0755);
        }
        foreach($tildapage->images as $file) {
            $arDownload[] = array(
                'from_url' => $file->from,
                'to_dir' => $upload_dir.$file->to
            );
        }

        return $arDownload;
    }
    
    /**
     * метод вызывается ajax-запросом из админки (hook)
     *  http://example.com/wp-admin/admin-ajax.php?action=tilda_admin_sync
     *
     */
    public static function ajax_sync()
    {
        $arResult = array();
        if(empty($_REQUEST['page_id']) || empty($_REQUEST['project_id']) || empty($_REQUEST['post_id'])) {
            $arResult['error'] = __('Bad request line. Missing parameter: projectid','tilda');
            echo json_encode($arResult);
            wp_die();
        }
        
        $project_id = intval($_REQUEST['project_id']);
        $page_id = intval($_REQUEST['page_id']);
        $post_id = intval($_REQUEST['post_id']);
        
        // запускаем экспорт
        $arDownload = self::export_tilda_page($page_id, $project_id, $post_id);

        if (is_wp_error($arDownload)){
            echo Tilda::json_errors($arDownload);
            wp_die();
        }

        if (!session_id()) {
            session_start();
        }

        $_SESSION['tildaexport'] = array(
            'arDownload' => $arDownload,
            'downloaded' => 0,
            'total' => sizeof($arDownload)
        );
        
        $arResult['total_download'] = $_SESSION['tildaexport']['total'];
        $arResult['need_download'] = $arResult['total_download'];
        $arResult['count_downloaded'] = 0;
        
        $arResult['page_id'] = $page_id;
        $arResult['project_id'] = $project_id;
        $arResult['post_id'] = $post_id;
        
        //$arResult['dump'] = $arDownload;
 
        echo json_encode($arResult);
        wp_die();
   }

     /**
     * метод вызывается ajax-запросом из админки
     *  http://example.com/wp-admin/admin-ajax.php?action=tilda_admin_export_file
     *  закачивает файлы порциями
     *
     */
   public static function ajax_export_file()
    {
        if (empty(self::$ts_start_plugin)) {
            self::$ts_start_plugin = time();
        }

        if (!session_id()) {
            session_start();
        }

        $arResult = array();

        if (empty($_SESSION['tildaexport']['arDownload'])) {
            $arResult['error'] = 'Error! All downloads';
            $arResult['dump'] = $_SESSION['tildaexport'];
            echo json_encode($arResult);
            die(0);
        }
        
        $arDownload = $_SESSION['tildaexport']['arDownload'];
        $arTmp = array();
        $downloaded=0;
        foreach ($arDownload as $file) {
            
            if (time() - self::$ts_start_plugin > 20) {
                $arTmp[] = $file;
            } else {
                if (! file_exists($file['to_dir'])) {
                    file_put_contents($file['to_dir'], file_get_contents($file['from_url']));
                }
                $downloaded++;
            }
        }
        
        $arDownload = $arTmp;
        
        $_SESSION['tildaexport']['arDownload'] = $arDownload;
        $_SESSION['tildaexport']['downloaded'] += $downloaded;

        $arResult['total_download'] = $_SESSION['tildaexport']['total'];
        $arResult['need_download'] = sizeof($arDownload); //$arResult['total_download'] - $_SESSION['tildaexport']['downloaded'];
        $arResult['count_downloaded'] = $_SESSION['tildaexport']['downloaded'];

        if ($arResult['need_download'] > 0 ) {
            $arResult['message'] = "Синхронизация заняла больше 30 секунд и все файлы не успелись синхронизироваться. Нажмите еще раз кнопку Синхронизировать для продолжения синхронизации.";
        }
        echo json_encode($arResult);
        wp_die();
    }
    
    public static function ajax_switcher_status()
    {
        if (empty($_REQUEST['post_id']) || empty($_REQUEST['tilda_status']) || !in_array($_REQUEST['tilda_status'], array('on', 'off'))) {
            echo json_encode(array('error' => __("Error. Can't find post with this 'post_id' parameter")));
            wp_die();
        }
        
        $post_id = intval($_REQUEST['post_id']);
        $meta = get_post_meta($post_id, '_tilda', true);
        if (empty($meta)) {
            $meta = array();
        }
        $meta['status'] = $_REQUEST['tilda_status'];
        update_post_meta($post_id, "_tilda", $meta);

        echo json_encode(array('result' => 'ok'));
        wp_die();
    }
}