<?php
if (!defined('MEDIAWIKI')) {
    die();
}

$wgExtensionFunctions[] = 'wfShoogleList';
$wgExtensionCredits['parserhook'][] = [
    'path' => __FILE__,
    'name' => 'ShoogleList',
    'version' => '1.0',
    'author' => 'Christopher Schirner',
    'url' => 'https://github.com/schinken/mediawiki-shooglelist',
    'description' => 'Generates a category list based on a project template'];

$wgResourceModules['ext.shooglelist'] = [
    'styles' => 'ShoogleList.css'
];

// Set up the new special page
$dir = dirname(__FILE__) . '/';
$wgExtensionMessagesFiles['ShoogleList'] = $dir . 'ShoogleList.i18n.php';

// Register parser-hook
function wfShoogleList() {
    new ShoogleList();
    new ShoogleListSortable();
}

function sort_status($a, $b) {
    return $a->get_status() == $b->get_status() ? 0 : ( $a->get_status() > $b->get_status() ) ? 1 : -1;
}

function sort_autor($a, $b) {
    return $a->get_autor() == $b->get_autor() ? 0 : ( $a->get_autor() > $b->get_autor() ) ? 1 : -1;
}

class ShoogleListSortable {

    private static $QUERY_PARAMETER = 'shoogleOrder';

    // these are mediawiki database fields
    static $SORTABLE_FIELDS = ['page_touched', 'page_id', 'cl_sortkey'];

    // these are keys from the template
    static $SORTABLE_KEYS = ['autor','status'];

    function __construct() {
        global $wgParser;
        $wgParser->setHook('shoogleSortable', [&$this, 'hookShoogleSortable']);
    }

    function hookShoogleSortable($category, $argv, $parser) {

        // we need at least one fields
        $fields = explode(",", $argv['fields']);
        if (count($fields) < 1) {
            return '';
        }
        $keys = explode(",", $argv['keys']);

        $selectedOption = false;
        if (isset($_REQUEST[self::$QUERY_PARAMETER]) && !empty($_REQUEST[self::$QUERY_PARAMETER])) {
            $selectedOption = $_REQUEST[self::$QUERY_PARAMETER];
        }

        $output = '<form method="GET" onchange="submit()" class="shoogle-sortable">';
        $output .= sprintf('<select name="%s">', self::$QUERY_PARAMETER);

        foreach ($fields as $field) {
            foreach (['DESC' => '-', 'ASC' => '+'] as $order => $symbol) {
                $value = $symbol.":field:".$field;
                $selected = ($value == $selectedOption) ? 'selected' : '';
                $output .= sprintf('<option value="%s" %s>%s (%s)</value>', $value, $selected,
                    wfMessage('field_' . $field)->text(),
                    wfMessage('sort_' . $order)->text());
            }
        }
        foreach ($keys as $key) {
            foreach (['DESC' => '-', 'ASC' => '+'] as $order => $symbol) {
                $value = $symbol.":key:".$key;
                $selected = ($value == $selectedOption) ? 'selected' : '';
                $output .= sprintf('<option value="%s" %s>%s (%s)</value>', $value, $selected,
                    wfMessage('key_' . $key)->text(),
                    wfMessage('sort_' . $order)->text());
            }
        }

        $output .= '</select>';
        $output .= '</form>';

        return $output;
    }

    static function getOrderTableAndDirection(array $fields, array $keys, $orderByField = 'page_id', $orderByKey = null, $orderByDirection = 'DESC') {

        if (isset($_REQUEST[self::$QUERY_PARAMETER]) && !empty($_REQUEST[self::$QUERY_PARAMETER])) {

            $req = explode(":", $_REQUEST[self::$QUERY_PARAMETER]);
            $req_count = count($req);

            if ($req_count == 1) {
               $req = ['+', 'field', '$_REQUEST[self::$QUERY_PARAMETER]'];
            }
            if ($req[0] == '-') {
                $reqOrderByDirection = 'DESC';
            } else {
                $reqOrderByDirection = 'ASC';
            }

            if ($req[1] == "field") {
                if (in_array($req[2], $fields)) {
                    $orderByField = $req[2];
                    $orderByDirection = $reqOrderByDirection;
                }
            } else {
                if (in_array($req[2], $keys)) {
                    $orderByKey = $req[2];
                    $orderByDirection = $reqOrderByDirection;
                }
            }
        }

        return [$orderByField, $orderByKey, $orderByDirection];
    }
}

class ShoogleList {

    // Default configuration
    private $settings = [];

    function __construct() {
        global $wgParser;
        $wgParser->setHook('shoogle', [&$this, 'hookShoogle']);
    }

    function hookShoogle($category, $argv, $parser) {
        $parser->disableCache();

        // Merge user specific settings with own defaults
        $this->settings = array_merge($this->settings, $argv);

        $localParser = new Parser();
        $category = $localParser->preprocess($category, $parser->mTitle, $parser->mOptions, false);

        // Set defaults to all articles
        if (isset($argv['defaultimg'])) {
            ShoogleList_Article::set_default('image', $argv['defaultimg']);
        }

        if (isset($argv['defaultdesc'])) {
            ShoogleList_Article::set_default('beschreibung', $argv['defaultdesc']);
        }

        // Retrieve internal wiki title of the category
        $title = Title::newFromText($category);

        list($orderByField, $orderByKey, $orderByDirection) = ShoogleListSortable::getOrderTableAndDirection(ShoogleListSortable::$SORTABLE_FIELDS,ShoogleListSortable::$SORTABLE_KEYS);

        // Retrieve all articles by current category
        $articles = $this->get_articles_by_category($title, $orderByField, $orderByKey, $orderByDirection);

        switch (@$argv['type']) {

            case 'potd':
                $output = $this->get_project_of_the_day($articles, $argv);
                break;
            case 'latest':
                $output = $this->get_latest_project($articles, $argv);
                break;
            default:
                $output = $this->get_project_list($articles, $argv);
                break;

        }

        global $wgOut;
        $wgOut->addModules('ext.shooglelist');

        $localParser = new Parser();
        $output = $localParser->parse($output, $parser->mTitle, $parser->mOptions);

        return $output->getText();
    }

    function get_project_of_the_day($articles, $argv) {

        // Check if there is a cached potd list, if yes, return
        if (($cache = $this->get_cache('shoogle_potd_cache')) !== false) {
            return $cache;
        }

        $limit = 4;
        if (isset($argv['limit'])) {
            $limit = (int)$argv['limit'];
        }

        // retrieve last videos by cache
        $last_potd = [];
        if (($cache = $this->get_cache('shoogle_potd_last')) !== false) {
            $last_potd = $cache;
        }

        $cnt_last_potd = count($last_potd);
        $filtered_articles = [];

        // filter videos
        foreach ($articles as $article) {

            // Skip invisible projects
            if (!$article->is_visible()) {
                continue;
            }

            // Filter videos shown last day
            if ($cnt_last_potd && in_array($article->get_title(), $last_potd)) {
                continue;
            }

            $filtered_articles[] = $article;
        }

        $random_articles = [];
        $last_potd = [];

        // Pick random projects
        do {
            $key = array_rand($filtered_articles);
            $article = $filtered_articles[$key];

            // Skip articles without image
            if (!$article->get_image()) {
                continue;
            }

            $random_articles[] = $article;
            $last_potd[] = $article->get_title();

            unset($filtered_articles[$key]);

        } while (count($random_articles) < $limit && count($filtered_articles) > 0);

        // Write last projects to cache
        $this->write_cache('shoogle_potd_last', $last_potd, 48 * 3600);

        // Render project list
        $output = $this->get_project_list($random_articles, $argv);

        // Cache to the next midnight.
        $dt = new DateTime('now');
        $dt->modify('tomorrow');

        $cachetime = $dt->getTimestamp() - time();
        $this->write_cache('shoogle_potd_cache', $output, $cachetime);

        return $output;
    }

    function get_latest_project($articles, $argv) {

        // Check if there is a cached potd list, if yes, return
        if (($cache = $this->get_cache('shoogle_latestproject_cache')) !== false) {
            return $cache;
        }

        $filtered_article = [];

        // filter videos
        foreach ($articles as $article) {

            // Skip invisible projects
            if (!$article->is_visible()) {
                continue;
            }
            $filtered_article[] = $article;
            if (count($filtered_article))
                break;
        }

        // Render project list
        $output = $this->get_project_list($filtered_article, $argv);

        // Cache to the next midnight.
        $dt = new DateTime('now');
        $dt->modify('tomorrow');

        $cachetime = $dt->getTimestamp() - time();
        $this->write_cache('shoogle_latestproject_cache', $output, $cachetime);

        return $output;
    }

    private function trim_text($text, $length, $abbrv = '...') {
        if (strlen($text) > $length) {
            return substr($text, 0, $length - strlen($abbrv)) . $abbrv;
        }

        return $text;
    }

    private function get_project_list($articles, $argv) {

        $thumb_size = 180;
        if (isset($argv['thumb_size'])) {
            $thumb_size = (int)$argv['thumb_size'];
        }

        $trim = false;
        if (isset($argv['trim_text'])) {
            $trim = (int)$argv['trim_text'];
        }

        $item_class = "shoogle-item";
        if (isset($argv['item_class'])) {
            $item_class = $argv['item_class'];
        }

        $output = '<div class="shoogle-box">';
        $output .= '<ul class="shoogle-list clearfix">';

        foreach ($articles as $article) {

            if (!$article->is_visible()) {
                continue;
            }

            $desc = $article->get_description();
            $abbrv_desc = $desc;
            if ($trim) {
                $abbrv_desc = $this->trim_text($desc, $trim);
            }

            $output .= sprintf('<li class=%s>', $item_class);
            $output .= sprintf('<span class="shoogle-title">[[%s|%s]]</span>', $article->get_title(), $article->get_name());
            $output .= sprintf('<span class="shoogle-image">[[Image:%1$s|%2$dpx|link=%3$s|alt=%3$s]]</span>', $article->get_image(), $thumb_size, $article->get_title());
            $output .= sprintf('<span class="shoogle-teaser" title="%s">%s</span>', $desc, $abbrv_desc);
            $output .= sprintf('<br /><span>%s</span>', $article->get_status());
            $output .= sprintf('<br /><span>%s</span>', $article->get_autor());
            $output .= '</li>';
        }

        $output .= '</ul>';
        $output .= '</div>';
        $output .= "__NOTOC__\n";

        return $output;
    }

    private function get_articles_by_category($Title, $orderByField = null, $orderByKey = null, $orderByDirection = 'DESC') {

        $dbr = wfGetDB(DB_SLAVE);

        // query database
        $res = $dbr->select(
            ['page', 'categorylinks'],
            ['page_title', 'page_namespace', $orderByField],
            ['cl_from = page_id', 'cl_to' => $Title->getDBKey()],
            "shoogleList",
            ['ORDER BY' => sprintf('%s %s', $orderByField, $orderByDirection)]
        );

        if ($res === false) {
            return [];
        }

        // convert results list into an array
        $articles = [];
        while ($Article = $dbr->fetchRow($res)) {
            $Title = Title::makeTitle($Article['page_namespace'], $Article['page_title']);
            if ($Title->getNamespace() != NS_CATEGORY) {
                $articles[] = new ShoogleList_Article($Title);
            }
        }

        // free the results
        $dbr->freeResult($res);

        if ($orderByKey) {
            $sortfunc = "sort_".$orderByKey;
            usort ($articles, $sortfunc);
            if ($orderByDirection == 'ASC') {
              $articles = array_reverse($articles);
            }
        }

        return $articles;
    }

    private function get_cache($key) {

        if (!function_exists('apc_fetch')) {
            return false;
        }

        return apc_fetch($key);
    }

    private function write_cache($key, $data, $cache_time) {
        if (function_exists('apc_store')) {
            apc_store($key, $data, $cache_time);
        }
    }

}

class ShoogleList_Article {

    private $title = null;
    private $wiki_article = null;
    private $content = '';
    private $attributes = [];

    private static $defaults = [
        'image' => '',
        'beschreibung' => '',
        'status' => 'unknown',
        'autor' => 'anonymous',
    ];

    function __construct($Title) {
        $this->title = $Title;
        $this->wiki_article = new Article($Title);

        $this->content = $this->content = $this->wiki_article->getPage()->getContent()->getNativeData();
        $this->process_attributes();
    }

    private function process_attributes() {

        $projectTag = '';
        if (preg_match('/{{Infobox Projekt(.*)}}/s', $this->content, $m)) {
            $projectTag = $m[1];
        }

        $attr = [
            'name' => $this->title,
            'image' => self::$defaults['image'],
            'beschreibung' => self::$defaults['beschreibung'],
            'status' => self::$defaults['status'],
            'autor' => self::$defaults['autor'],
            'visible' => true,
        ];

        foreach ($attr as $key => $value) {
            if (preg_match('/\|' . $key . '\s*=(.*)$/m', $projectTag, $m)) {
                $val = trim($m[1]);
                if (!empty($val)) {
                    $attr[$key] = $val;
                }
            }
        }

        $this->attributes = $attr;
    }

    private function get_attribute($Key, $Default = null) {
        if (!isset($this->attributes[$Key])) {
            return $Default;
        }

        return $this->attributes[$Key];
    }

    static function set_default($key, $value) {
        self::$defaults[$key] = $value;
    }

    function get_image($Default = '') {
        return $this->get_attribute('image', $Default);
    }

    function get_description($Default = '') {
        return $this->get_attribute('beschreibung', $Default);
    }

    function get_name($Default = '') {
        return $this->get_attribute('name', $Default);
    }

    function get_status($Default = '') {
        return $this->get_attribute('status', $Default);
    }

    function get_autor($Default = '') {
        return $this->get_attribute('autor', $Default);
    }

    function is_visible() {
        return ($this->get_attribute('visible') === true);
    }

    function get_title() {
        return (string)$this->title;
    }

    function get_content() {
        return $this->content;
    }
}
