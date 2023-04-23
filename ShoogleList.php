<?php
if (!defined('MEDIAWIKI')) {
    die();
}

$wgExtensionFunctions[] = 'wfShoogleList';
$wgExtensionCredits['parserhook'][] = [
    'path' => __FILE__,
    'name' => 'ShoogleList',
    'version' => '1.1',
    'author' => 'Christopher Schirner, Andreas Frisch',
    'url' => 'https://github.com/schaffenburg/mediawiki-shooglelist',
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
    new ShoogleProjectList();
    new ShoogleEventList();
    new ShoogleListSortable();
}

use MediaWiki\MediaWikiServices;

function sort_status($a, $b) {
    return $a->get_status() == $b->get_status() ? 0 : ( $a->get_status() > $b->get_status() ) ? 1 : -1;
}

function sort_autor($a, $b) {
    return $a->get_autor() == $b->get_autor() ? 0 : ( $a->get_autor() > $b->get_autor() ) ? 1 : -1;
}

function sort_datum($a, $b) {
    return $a->get_date() == $b->get_date() ? 0 : ( $a->get_date() > $b->get_date() ) ? 1 : -1;
}

function sort_kategorie($a, $b) {
    return $a->get_category() == $b->get_category() ? 0 : ( $a->get_category() > $b->get_category() ) ? 1 : -1;
}

class ShoogleListSortable {

    private static $QUERY_PARAMETER = 'shoogleOrder';

    // these are mediawiki database fields
    static $SORTABLE_FIELDS = ['page_touched', 'page_id', 'cl_sortkey'];

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
        } else if (isset($argv['default'])) {
            $selectedOption = $argv['default'];
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
    protected $settings = [];

    // these are keys from the template
    static $SORTABLE_KEYS = [];

    function hookShoogleListBase($category, $argv, $parser) {
        $parser->getOutput()->updateCacheExpiry(0);

        // Merge user specific settings with own defaults
        $this->settings = array_merge($this->settings, $argv);

        // Set defaults to all articles
        if (isset($argv['defaultimg'])) {
            ShoogleList_Article::set_default('image', $argv['defaultimg']);
        }

        if (isset($argv['defaultdesc'])) {
            ShoogleList_Article::set_default('beschreibung', $argv['defaultdesc']);
        }
    }

    protected function get_articles_by_category($new_article_closure, $template, $title, $orderByField = null, $orderByKey = null, $orderByDirection = 'DESC') {
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbr = $lb->getConnectionRef(DB_REPLICA);

        // query database
        $res = $dbr->select(
            ['page', 'categorylinks'],
            ['page_title', 'page_namespace', $orderByField],
            ['cl_from = page_id', 'cl_to' => $title->getDBKey()],
            "shoogleList",
            ['ORDER BY' => sprintf('%s %s', $orderByField, $orderByDirection)]
        );

        if ($res === false) {
            echo("<!-- ShoogleList::get_articles_by_category $dbr->select failed -->");
            return [];
        }

        // convert results list into an array
        $articles = [];

        while ($Article = $dbr->fetchRow($res)) {
            $title = title::makeTitle($Article['page_namespace'], $Article['page_title']);
            if ($title->getNamespace() != NS_CATEGORY) {
                $articles[] = $new_article_closure($template, $title);
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

    protected function trim_text($text, $length, $abbrv = '...') {
        if (strlen($text) > $length) {
            return substr($text, 0, $length - strlen($abbrv)) . $abbrv;
        }

        return $text;
    }

    protected function get_cache($key) {

        if (!function_exists('apc_fetch')) {
            return false;
        }

        return apc_fetch($key);
    }

    protected function write_cache($key, $data, $cache_time) {
        if (function_exists('apc_store')) {
            apc_store($key, $data, $cache_time);
        }
    }
}

class ShoogleProjectList extends ShoogleList {
    // these are keys from the template
    static $SORTABLE_KEYS = ['autor', 'status'];

    function __construct() {
        global $wgParser;
        $wgParser->setHook('shoogle', [&$this, 'hookShoogleProjectList']);
        $wgParser->setHook('shoogleProjectList', [&$this, 'hookShoogleProjectList']);
    }

    function hookShoogleProjectList($category, $argv, $parser) {
        $this->hookShoogleListBase($category, $argv, $parser);

        $localParser = new Parser();
        $category = $localParser->preprocess($category, $parser->mTitle, $parser->mOptions, false);

        $template = "Projekt";
        if (isset($argv['template'])) {
            $template = $argv['template'];
        }

        // Retrieve internal wiki title of the category
        $title = Title::newFromText($category);

        $new_article_closure = function ($template, $title) {
            return new ShoogleList_ProjectArticle($template, $title);
        };

        list($orderByField, $orderByKey, $orderByDirection) = ShoogleListSortable::getOrderTableAndDirection(ShoogleListSortable::$SORTABLE_FIELDS,self::$SORTABLE_KEYS);

        // Retrieve all articles by current category
        $articles = $this->get_articles_by_category($new_article_closure, $template, $title, $orderByField, $orderByKey, $orderByDirection);

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
            if (!in_array ($article->get_status(), ["beta", "unstable", "stable"])) {
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
            $output .= sprintf('<br /><span>%s</span>', $article->get_autor_link());
            $output .= '</li>';
        }

        $output .= '</ul>';
        $output .= '</div>';
        $output .= "__NOTOC__\n";

        return $output;
    }
}

class ShoogleEventList extends ShoogleList {
    // these are keys from the template
    static $SORTABLE_KEYS = ['datum', 'kategorie'];

    function __construct() {
        global $wgParser;
        $wgParser->setHook('shoogleEventList', [&$this, 'hookShoogleEventList']);
    }

    function hookShoogleEventList($category, $argv, $parser) {
        $this->hookShoogleListBase($category, $argv, $parser);

        $localParser = new Parser();
        $category = $localParser->preprocess($category, $parser->mTitle, $parser->mOptions, false);

        $template = "Veranstaltung";
        if (isset($argv['template'])) {
            $template = $argv['template'];
        }

        // Retrieve internal wiki title of the category
        $title = Title::newFromText($category);

        $new_article_closure = function ($template, $title) {
            return new ShoogleList_EventArticle($template, $title);
        };

        list($orderByField, $orderByKey, $orderByDirection) = ShoogleListSortable::getOrderTableAndDirection(ShoogleListSortable::$SORTABLE_FIELDS,self::$SORTABLE_KEYS);

        // Retrieve all articles by current category
        $articles = $this->get_articles_by_category($new_article_closure, $template, $title, $orderByField, $orderByKey, $orderByDirection);

        switch (@$argv['type']) {
            case 'upcoming':
                $output = $this->get_upcoming_events($articles, $argv);
                break;
            case 'past':
                $output = $this->get_past_events($articles, $argv);
                break;
            default:
                $output = $this->get_event_list($articles, $argv);
                break;
        }

        global $wgOut;
        $wgOut->addModules('ext.shooglelist');

        $localParser = new Parser();
        $output = $localParser->parse($output, $parser->mTitle, $parser->mOptions);

        return $output->getText();
    }

    private function get_event_list($articles, $argv) {

        $output = '<div>';
        $output .= '<ul>';

        foreach ($articles as $article) {

            if (!$article->is_visible()) {
                continue;
            }

            $desc = $article->get_description();
            $abbrv_desc = $desc;

            $output .= sprintf('<li>');
            $output .= sprintf('<span>[[%s|%s]]</span>', $article->get_title(), $article->get_name());
            $output .= sprintf(' <span>%s</span>', $desc, $abbrv_desc);
            $output .= sprintf(' <span>%s</span>', $article->get_date());
            $output .= sprintf(' <span>%s</span>', $article->get_place());
            $output .= sprintf(' <span>%s</span>', $article->get_category());
            $output .= sprintf(' <span>%s</span>', $article->get_organizer());
            $output .= '</li>';
        }

        $output .= '</ul>';
        $output .= '</div>';
        $output .= "__NOTOC__\n";

        return $output;
    }

    private function get_event_shortlist($articles, $argv) {

        $output = '<div>';
        $output .= '<ul>';

        foreach ($articles as $article) {

            if (!$article->is_visible()) {
                continue;
            }

            $desc = $article->get_description();
            $abbrv_desc = $desc;

            $output .= sprintf('<li>');
            $output .= sprintf('<span>[[%s|%s]]</span>', $article->get_title(), $article->get_name());
            $output .= sprintf(' <span>%s</span>', $article->get_date());
            $output .= '</li>';
        }

        $output .= '</ul>';
        $output .= '</div>';
        $output .= "__NOTOC__\n";

        return $output;
    }

    private function get_upcoming_events($articles, $argv) {
        if (($cache = $this->get_cache('shoogle_upcoming_events_cache')) !== false) {
            return $cache;
        }

        $limit = 4;
        if (isset($argv['limit'])) {
            $limit = (int)$argv['limit'];
        }

        usort ($articles, "sort_datum");

        $dt = new DateTime('now');

        $upcoming_articles = [];

        foreach ($articles as $article) {
            $article_date = date_create_from_format ("Y-m-d+", $article->get_date());

            if (!$article_date) {
               continue;
            }

            if ($article_date >= $dt) {
                $upcoming_articles[] = $article;
            }
            else
            if (count($upcoming_articles) >= $limit) {
                break;
            }
        }

        $output = $this->get_event_shortlist($upcoming_articles, $argv);

        $dt->modify('tomorrow');
        $cachetime = $dt->getTimestamp() - time();
        $this->write_cache('shoogle_upcoming_events_cache', $output, $cachetime);

        return $output;
    }

    private function get_past_events($articles, $argv) {
        if (($cache = $this->get_cache('shoogle_past_events_cache')) !== false) {
            return $cache;
        }

        $limit = 4;
        if (isset($argv['limit'])) {
            $limit = (int)$argv['limit'];
        }

        usort ($articles, "sort_datum");
        $articles = array_reverse($articles);

        $dt = new DateTime('now');

        $past_articles = [];

        foreach ($articles as $article) {
            $article_date = date_create_from_format ("Y-m-d+", $article->get_date());

            if (!$article_date) {
               continue;
            }
            if ($article_date < $dt) {
                $past_articles[] = $article;
            }
            if (count($past_articles) >= $limit) {
                break;
            }
        }

        $output = $this->get_event_shortlist($past_articles, $argv);

        $dt->modify('tomorrow');
        $cachetime = $dt->getTimestamp() - time();
        $this->write_cache('shoogle_past_events_cache', $output, $cachetime);

        return $output;
    }
}

class ShoogleList_Article {

    protected $title = null;
    protected $wiki_article = null;
    protected $content = '';
    protected static $defaults = [
        'name' => "noname",
        'visible' => true,
        'image' => '',
        'beschreibung' => '',
    ];

    protected $attributes = [];

    function __construct($template, $title) {
        $this->title = $title;
        $this->wiki_article = new Article($title);
        $this->content = $this->wiki_article->getPage()->getContent()->getNativeData();
        $this->attributes = self::$defaults;
        $this->process_attributes($template);
    }

    protected function process_attributes($template) {
        $projectTag = '';
        if (preg_match('/{{Infobox '.$template.'(.*)}}/s', $this->content, $m)) {
            $projectTag = $m[1];
        }

        $this->attributes['name'] = $this->title;

        foreach ($this->attributes as $key => $value) {
            if (preg_match('/\|' . $key . '\s*=(.*)$/m', $projectTag, $m)) {
                $val = trim($m[1]);
                if (!empty($val)) {
                    $this->attributes[$key] = $val;
                }
            } else {
                $this->attributes[$key] = self::$defaults[$key];
            }
        }
    }

    protected function get_attribute($Key, $Default = null) {
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

class ShoogleList_ProjectArticle extends ShoogleList_Article {

    function __construct($template, $title) {
        $this->set_default('status', 'unknown');
        $this->set_default('autor', 'anonymous');
        parent::__construct($template, $title);
    }

    function get_status($Default = '') {
        return $this->get_attribute('status', $Default);
    }

    function get_autor($Default = '') {
        return $this->get_attribute('autor', $Default);
    }

    function get_autor_link($Default = '') {
        $autorlink = $this->get_attribute('autor', $Default);
        if (substr( $autorlink, 0, 11 ) !== "[[Benutzer:") {
            $autorlink = sprintf('[[Benutzer:%1$s|%1$s]]', $autorlink);
        }
        return $autorlink;
    }
}

class ShoogleList_EventArticle extends ShoogleList_Article {

    function __construct($template, $title) {
        $this->set_default('kategorie', 'uncategorized');
        $this->set_default('datum', '1970-01-01');
        $this->set_default('ort', 'nowhere');
        $this->set_default('organisator', 'anonymous');
        parent::__construct($template, $title);
    }

    function get_date($Default = '') {
        return $this->get_attribute('datum', $Default);
    }

    function get_category($Default = '') {
        return $this->get_attribute('kategorie', $Default);
    }

    function get_place($Default = '') {
        return $this->get_attribute('ort', $Default);
    }

    function get_organizer($Default = '') {
        return $this->get_attribute('organisator', $Default);
    }

    function get_organizer_link($Default = '') {
        return $this->get_attribute('organisator', $Default);
    }
}
