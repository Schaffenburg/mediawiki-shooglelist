<?php
if (!defined('MEDIAWIKI')) die();

$wgExtensionFunctions[] = 'wfShoogleList';
$wgExtensionCredits['parserhook'][] = array(
        'path'            => __FILE__,
        'name'            => 'ShoogleList',
        'version'         => '0.00001',
        'author'          => 'Schinken',
        'url'             => 'http://www.hackerspace-bamberg.de',
        'description'     => 'Generates a category list in Shoogle-Style'  );

// Set up the new special page
$dir = dirname( __FILE__ ) . '/';
$wgExtensionMessagesFiles['ShoogleList'] = $dir . 'ShoogleList.i18n.php';

// Register parser-hook
function wfShoogleList() {
    new ShoogleList();
    new ShoogleListSortable();
}


class ShoogleListSortable {

    private static $QUERY_PARAMETER = 'shoogleOrder';

    public function __construct() {
        global $wgParser;
        $wgParser->setHook('shoogleSortable', array(&$this, 'hookShoogleSortable'));
    }


    public function hookShoogleSortable($category, $argv, $parser) {

        $defaultField = 'cl_sortkey';
	if(isset($argv['defaultField'])) {
            $defaultField = $argv['defaultField'];
        }

        $defaultDirection = 'ASC';
        if(isset($argv['defaultDirection'])) {
            $defaultDirection = $argv['defaultDirection'];
        }

        // we need at least one fields
        $fields = explode(",", $argv['fields']);
        if(count($fields) < 1) {
            return '';
        }

        $selectedField = false;
        if(isset($_REQUEST[self::$QUERY_PARAMETER]) && !empty($_REQUEST[self::$QUERY_PARAMETER])) {
            $selectedField = $_REQUEST[self::$QUERY_PARAMETER];
        }

        $output = '<form method="GET" onchange="submit()" class="shoogle-sortable">';
        $output .= sprintf('<select name="%s">', self::$QUERY_PARAMETER);

        foreach($fields as $field) {
            foreach(array('DESC' => '-', 'ASC' => '') as $order => $symbol) {
                $selected = ($symbol.$field == $selectedField) ? 'selected' : '';
                $output .= sprintf('<option value="%s" %s>%s (%s)</value>', $symbol.$field, $selected,
                                                                            wfMessage('field_'.$field)->text(),
                                                                            wfMessage('sort_'.$order)->text());
            }
        }

        $output .= '</select>';
        $output .= '</form>';
        
        return $output;
    }

    public static function getOrderTableAndDirection(array $fields, $orderByField = 'cl_sortkey', $orderByDirection = 'ASC') {

        if(isset($_REQUEST[self::$QUERY_PARAMETER]) && !empty($_REQUEST[self::$QUERY_PARAMETER])) {

            $reqValue = $_REQUEST[self::$QUERY_PARAMETER];

            if($reqValue[0] == '-') {
                $reqOrderByDirection = 'DESC';
                $reqValue = substr($reqValue, 1);
            } else {
                $reqOrderByDirection = 'ASC';
            }

            if(in_array($reqValue, $fields)) {
                $orderByField = $reqValue;
                $orderByDirection = $reqOrderByDirection;
            }
	}

        return array($orderByField, $orderByDirection);
    }
}

class ShoogleList {

    private static $SORTABLE_FIELDS = array('cl_sortkey', 'page_id');

    // Default configuration
    private $settings = array(
            );

    public function __construct() {
        global $wgParser;
        $wgParser->setHook('shoogle', array(&$this, 'hookShoogle'));
    }


    public function hookShoogle($category, $argv, $parser) {

        $parser->disableCache();

        // Merge user specific settings with own defaults
        $this->settings = array_merge($this->settings, $argv);

        $localParser = new Parser();
        $category = $localParser->preprocess($category, $parser->mTitle, $parser->mOptions, false);

        // Set defaults to all articles
        if( isset( $argv['defaultimg'] ) ) {
            ShoogleList_Article::set_default('image', $argv['defaultimg']);
        }

        if( isset( $argv['defaultdesc'] ) ) {
            ShoogleList_Article::set_default('beschreibung', $argv['defaultdesc']);
        }

        // Retrieve internal wiki title of the category
        $title = Title::newFromText($category);

        list($orderByField, $orderByDirection) = ShoogleListSortable::getOrderTableAndDirection(self::$SORTABLE_FIELDS, 'cl_sortkey', 'ASC');

        // Retrieve all articles by current category
        $articles = $this->get_articles_by_category($title, $orderByField, $orderByDirection);

        switch( @$argv['type'] ) {

            case 'potd':    
                $output = $this->get_project_of_the_day($articles, $argv);
                break;

            default:
                $output = $this->get_project_list($articles, $argv);
                break;

        }


        global $wgOut, $wgScriptPath;
        $wgOut->addExtensionStyle("{$wgScriptPath}/extensions/ShoogleList/ShoogleList.css?x=y");

        $localParser = new Parser();
        $output = $localParser->parse($output, $parser->mTitle, $parser->mOptions);
        return $output->getText();

    }

    /**
    * Renders a list of "projects of the day" and cache them for at least 24h
    * 
    * @param ShoogleList_Articles[] $articles Articles
    * @param array $argv ShoogleList Arguments
    */

    private function get_project_of_the_day( $articles, $argv ) {

        // Check if there is a cached potd list, if yes, return
        if( ($cache = $this->get_cache('shoogle_potd_cache')) !== false ) {
            return $cache;
        }

        $limit = 4;
        if( isset( $argv['limit'] ) ) {
            $limit = (int) $argv['limit'];    
        }

        // retrieve last videos by cache
        $last_potd = array();
        if( ($cache = $this->get_cache('shoogle_potd_last')) !== false ) {
            $last_potd = $cache;    
        }

        $cnt_last_potd = count($last_potd);
        $filtered_articles = array();

        // filter videos
        foreach( $articles as $article ) {

            // Skip invisible projects
            if( !$article->is_visible() ) {
                continue;    
            }

            // Filter videos shown last day
            if( $cnt_last_potd && in_array( $article->get_title(), $last_potd ) ) {
                continue;    
            }

            $filtered_articles[] = $article;
        }

        $random_articles = array();
        $last_potd = array();

        // Pick random projects
        do {
            $key = array_rand( $filtered_articles );
            $article = $filtered_articles[$key];

            // Skip articles without image
            if( !$article->get_image() ) {
                continue;
            }

            $random_articles[] = $article;
            $last_potd[] = $article->get_title();

            unset( $filtered_articles[$key] );

        }while( count( $random_articles ) < $limit && count( $filtered_articles ) > 0 );

        // Write last projects to cache
        $this->write_cache('shoogle_potd_last', $last_potd, 48*3600 );

        // Render project list
        $output = $this->get_project_list( $random_articles, $argv );

        // Cache to the next midnight.
        $dt = new DateTime('now');
        $dt->modify('tomorrow');

        $cachetime = $dt->getTimestamp() - time();
        $this->write_cache('shoogle_potd_cache', $output, $cachetime);

        return $output;
    }

    private function trim_text($text, $length, $abbrv='...') {
        if( strlen($text) > $length ) {
            return substr($text, 0, $length-strlen($abbrv)).$abbrv;
        }

        return $text;
    }

    /**
    * Renders a list of articles in wiki format
    * 
    * @param ShoogleList_Articles[] $articles Articles
    * @param array $argv ShoogleList Arguments
    */

    private function get_project_list( $articles, $argv ) {

        $thumb_size = 180;
	if( isset( $argv['thumb_size']) ) {
            $thumb_size = (int) $argv['thumb_size'];
	}

        $trim = false;
        if( isset( $argv['trim_text'] ) ) {
            $trim = (int) $argv['trim_text'];
        }


        $output = '<div class="shoogle-box">';
        $output .= '<ul class="shoogle-list clearfix">';

        foreach( $articles as $article ) {

            if( !$article->is_visible() ) {
                continue;    
            }

            $desc = $article->get_description();
            $abbrv_desc = $desc;
            if( $trim ) {
                $abbrv_desc = $this->trim_text($desc, $trim);
            }

            $output .= '<li class="shoogle-item">';
            $output .= sprintf('<span class="shoogle-title">[[%s|%s]]</span>', $article->get_title(), $article->get_name() );
            $output .= sprintf('<span class="shoogle-image">[[Image:%1$s|%2$dpx|link=%3$s|alt=%3$s]]</span>', $article->get_image(), $thumb_size, $article->get_title() );
            $output .= sprintf('<span class="shoogle-teaser" title="%s">%s</span>', $desc, $abbrv_desc );
            $output .= '</li>';
        }


        $output .= '</ul>';
        $output .= '</div>';
        $output .= "__NOTOC__\n";
    
        return $output;
    }


    private function get_articles_by_category($Title, $orderByField = 'cl_sortkey', $orderByDirection = 'DESC') {

        $dbr = wfGetDB(DB_SLAVE);


        // query database
        $res = $dbr->select(
                array('page', 'categorylinks'),
                array('page_title', 'page_namespace', $orderByField),
                array('cl_from = page_id', 'cl_to' => $Title->getDBKey()),
		"shoogleList",
                array('ORDER BY' => sprintf('%s %s', $orderByField, $orderByDirection))
                );

        if( $res === false ) {
            return array();
        }

        // convert results list into an array
        $Articles = array();
        while ( $Article = $dbr->fetchRow( $res ) ) {
            $Title = Title::makeTitle( $Article['page_namespace'], $Article['page_title'] );
            if ( $Title->getNamespace() != NS_CATEGORY ) {
                $Articles[] = new ShoogleList_Article( $Title );
            }
        }

        // free the results
        $dbr->freeResult($res);

        return $Articles;
    }

    /**
    * Get cached data by $key, if apc is available
    *
    * @param string $key apc cache key
    * @return bool|mixed
    */

    private function get_cache( $key ) {

        if( !function_exists('apc_fetch') ) {
            return false;    
        }    

        return apc_fetch( $key );
    } 

    /**
    * Writes data to apc cache by $key
    *
    * @param string $key apc cache key
    */

    private function write_cache( $key, $data, $cache_time ) {
        if( function_exists('apc_store') ) {
            apc_store( $key, $data, $cache_time );
        }
    }

}

class ShoogleList_Article {

    private $title   = null;
    private $wiki_article = null;
    private $content = '';
    private $attributes = array();

    private static $defaults = array(
        'image'         => '',
        'beschreibung'  => ''
    );

    public function __construct( $Title ) {

        $this->title = $Title;
        $this->wiki_article = new Article( $Title );

        $this->content = $this->wiki_article->getContent();
        $this->process_attributes();
    }

    private function process_attributes() {

        $projectTag = '';
        if( preg_match('/{{Infobox Projekt(.*)}}/s', $this->content, $m ) ) {
            $projectTag = $m[1];
        }

        $attr = array (
                'name'          => $this->title,
                'image'         => self::$defaults['image'],
                'beschreibung'  => self::$defaults['beschreibung'],
                'visible'       => true
            );

        foreach( $attr as $key => $value ) {
            if( preg_match('/\|'.$key.'\s*=(.*)$/m', $projectTag, $m ) ) {
                $val = trim( $m[1] );
                if( !empty( $val ) ) {
                    $attr[ $key ] = $val;
                }
            }
        }

        $this->attributes = $attr;
    }

    private function get_attribute( $Key, $Default = null ) {
        if( !isset( $this->attributes[ $Key ] ) ) {
            return $Default;    
        }      

        return $this->attributes[ $Key ];
    }

    public static function set_default( $key, $value ) {
        self::$defaults[ $key ] = $value;    
    }

    public function get_image( $Default = '' ) {
        return $this->get_attribute('image', $Default);    
    }

    public function get_description( $Default = '' ) {
        return $this->get_attribute('beschreibung', $Default);    
    }

    public function get_name( $Default = '' ) {
        return $this->get_attribute('name', $Default);    
    }

    public function is_visible() {
        return ($this->get_attribute('visible') === true);
    }

    public function get_title() {
        return (string) $this->title;
    }

    public function get_content() {
        return $this->content;       
    }

}
