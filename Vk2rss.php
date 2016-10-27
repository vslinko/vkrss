<?php

/**
 * @Description: vk.com Wall to RSS Class
 *
 * Initial creators
 * @author tsarbas
 * @author: kadukmm <nikolay.kaduk@gmail.com>
 **/

/**
 * Refactoring and featuring:
 *   including & excluding conditions,
 *   title generation,
 *   description extraction from attachment
 *   hashtags extractor to 'category' tags
 * @author woxcab
 **/

require_once('utils.php');

class Vk2rss
{
    const HASH_TAG_REGEX = '#([а-яёА-ЯЁa-zA-Z0-9_]+)(?:@[a-zA-Z0-9_]+)?';
    /**
     * Delimiter of text from different parts of post
     */
    const VERTICAL_DELIMITER = ' <br/> ________________ <br/> ';
    /**
     * Default title value when no text in the post
     */
    const EMPTY_POST_TITLE = '[Без текста]';
    /**
     * Prefix of feed description for user wall
     */
    const USER_FEED_DESCRIPTION_PREFIX = "Стена пользователя ";
    /**
     * Prefix of feed description for group wall
     */
    const GROUP_FEED_DESCRIPTION_PREFIX = "Стена группы ";
    /**
     * Maximum title length in symbols
     */
    const MAX_TITLE_LENGTH = 80;
    /**
     * Required minimum number of symbols in second or following paragraphs in order to use its for title
     */
    const MIN_PARAGRAPH_LENGTH_FOR_TITLE = 30;
    /**
     * URL of API method that returns wall posts
     */
    const API_BASE_URL = 'http://api.vk.com/method/'; # do not change on HTTPS, connection wrapper do it itself if HTTPS is supported

    /**
     * @var int   identifier of user or group which wall going to be extracted
     */
    protected $owner_id;
    /**
     * @var string   short address of user or group which wall going to be extracted
     */
    protected $domain;
    /**
     * @var int   quantity of last posts from the wall (at most 100)
     */
    protected $count;
    /**
     * @var string   case insensitive regular expression that have to match text of post
     */
    protected $include;
    /**
     * @var string   case insensitive regular expression that have not to match text of post
     */
    protected $exclude;

    /**
     * @var ProxyDescriptor|null   Proxy descriptor
     */
    protected $proxy = null;


    /**
     * Get posts of wall
     *
     * @param ConnectionWrapper $connector
     * @param string $api_method   API method name
     * @return mixed   Json VK response in appropriate PHP type
     * @throws Exception   If unsupported API method name is passed or data retrieving is failed
     */
    protected function getContent($connector, $api_method)
    {
        $url = self::API_BASE_URL . $api_method . '?';
        switch ($api_method) {
            case "wall.get":
                if (!empty($this->domain)) {
                    $url .= "domain={$this->domain}";
                } else {
                    $url .= "owner_id={$this->owner_id}";
                }
                $url .= "&count={$this->count}";
                break;
            case "users.get":
                $url .= "fields=first_name,last_name&user_ids=" . (!empty($this->domain) ? $this->domain : $this->owner_id);
                break;
            case "groups.getById":
                $url .= "fields=name&group_id=" . (!empty($this->domain) ? $this->domain : abs($this->owner_id));
                break;
            default:
                throw new Exception("Passed unsupported API method name '${api_method}'", 400);
        }
        $connector->openConnection();
        $content = null;
        try {
            $content = $connector->getContent($url, null, true);
            $connector->closeConnection();
        } catch (Exception $exc) {
            $connector->closeConnection();
            throw new Exception("Failed to get content of URL ${url}: " . $exc->getMessage(), $exc->getCode());
        }
        return json_decode($content);
    }


    /**
     * Generate title using text of post
     *
     * @param $description string   text of post
     * @return string   generated title
     */
    protected function getTitle($description)
    {
        $description = preg_replace('/(^(<br\/?>\s*?)+|(<br\/?>\s*?)+$|(<br\/?>\s*?)+(?=<br\/?>))/u', '', $description); // remove superfluous <br>
        $description = preg_replace('/<(?!br|br\/)[^>]+>/u', '', $description); // remove all tags exclude <br> (but leave all other tags that starts with 'br'...)

        if (empty($description)) {
            return self::EMPTY_POST_TITLE;
        }

        $description_pars = preg_split("/<br\/?>/u", $description);
        $curr_title_length = 0;
        $par_idx = 0;

        if (!function_exists('remove_underscores_from_hash_tag')) {
            function remove_underscores_from_hash_tag($match)
            {
                return str_replace('_', ' ', $match[1]);
            }
        }

        foreach ($description_pars as $par_idx => &$paragraph) {
            $paragraph = trim($paragraph);
            if (preg_match('/^\s*(?:' . self::HASH_TAG_REGEX . '\s*)*$/u', $paragraph) === 1) {
                // paragraph contains only hash tags
                unset($description_pars[$par_idx]);
                continue;
            }
            // hash tags (if exist) are semantic part of paragraph
            $paragraph = preg_replace_callback('/' . self::HASH_TAG_REGEX . '/u',
                                               'remove_underscores_from_hash_tag',
                                               $paragraph);

            if ($curr_title_length < self::MAX_TITLE_LENGTH
                    && (mb_strlen($paragraph) >= self::MIN_PARAGRAPH_LENGTH_FOR_TITLE
                    || $curr_title_length + self::MIN_PARAGRAPH_LENGTH_FOR_TITLE < self::MAX_TITLE_LENGTH)) {
                if (!in_array(mb_substr($paragraph, -1), array('.', '!', '?', ',', ':', ';'))) {
                    $paragraph .= '.';
                }
                $curr_title_length += mb_strlen($paragraph);
            } else {
                break;
            }
        }

        $full_title = implode(' ', array_slice($description_pars, 0, $par_idx));
        if (mb_strlen($full_title) > self::MAX_TITLE_LENGTH) {
            $split = preg_split('/\s+/u', utf8_strrev(mb_substr($full_title, 0, self::MAX_TITLE_LENGTH)), 2);
            $full_title = utf8_strrev($split[1]);

            $last_char = mb_substr($full_title, -1);
            if (in_array($last_char, array(',', ':', ';', '-'))) {
                $full_title = mb_substr($full_title, 0, -1) . '...';
            } elseif (!in_array($last_char, array('.', '!', '?', ')'))) {
                $full_title .= '...';
            }
        }
        $full_title = mb_strtoupper(mb_substr($full_title, 0, 1)) . mb_substr($full_title, 1);
        return $full_title;
    }

    public function __construct($id, $count = 20, $include = null, $exclude = null,
                                $proxy = null, $proxy_type = null, $proxy_login = null, $proxy_password = null)
    {
        if (empty($id)) {
            throw new Exception("Empty identifier of user or group is passed", 400);
        }

        if (strcmp(substr($id, 0, 2), 'id') === 0 && ctype_digit(substr($id, 2))) {
            $this->owner_id = (int)substr($id, 2);
            $this->domain = null;
        } elseif (strcmp(substr($id, 0, 4), 'club') === 0 && ctype_digit(substr($id, 4))) {
            $this->owner_id = -(int)substr($id, 4);
            $this->domain = null;
        } elseif (strcmp(substr($id, 0, 5), 'event') === 0 && ctype_digit(substr($id, 5))) {
            $this->owner_id = -(int)substr($id, 5);
            $this->domain = null;
        } elseif (strcmp(substr($id, 0, 6), 'public') === 0 && ctype_digit(substr($id, 6))) {
            $this->owner_id = -(int)substr($id, 6);
            $this->domain = null;
        } elseif (is_numeric($id) && is_int(abs($id))) {
            $this->owner_id = (int)$id;
            $this->domain = null;
        } else {
            $this->owner_id = null;
            $this->domain = $id;
        }
        $this->count = $count;
        $this->include = isset($include) ? preg_replace("/(?<!\\\)\//u", "\\/", $include) : null;
        $this->exclude = isset($exclude) ? preg_replace("/(?<!\\\)\//u", "\\/", $exclude) : null;
        if (isset($proxy)) {
            try {
                $this->proxy = new ProxyDescriptor($proxy, $proxy_type, $proxy_login, $proxy_password);
            } catch (Exception $exc) {
                throw new Exception("Invalid proxy parameters: " . $exc->getMessage(), 400);
            }
        }
    }

    /**
     * Generate RSS feed as output
     */
    public function generateRSS()
    {
        include('FeedWriter.php');

        $outer_encoding = mb_internal_encoding();
        if (!mb_internal_encoding("UTF-8")) {
            throw new Exception("Cannot set encoding UTF-8 for multibyte strings", 500);
        }

        $connector = new ConnectionWrapper($this->proxy);

        if (!empty($this->domain) || (!empty($this->owner_id) && $this->owner_id < 0)) {
            $group_response = $this->getContent($connector, "groups.getById");
            if (property_exists($group_response, 'error') && $group_response->error->error_code != 100) {
                throw new APIError($group_response->error->error_msg,
                    $group_response->error->error_code,
                    $connector->getLastUrl());
            }
        }
        if (isset($group_response) && !property_exists($group_response, 'error') && !empty($group_response->response)) {
            $group = $group_response->response[0];
            $title = $group->name;
            $description = self::GROUP_FEED_DESCRIPTION_PREFIX . $group->name;
        } else {
            $user_response = $this->getContent($connector, "users.get");
            if (property_exists($user_response, 'error')) {
                throw new APIError($user_response->error->error_msg,
                    $user_response->error->error_code, $connector->getLastUrl());
            }
            if (!empty($user_response->response)) {
                $profile = $user_response->response[0];
                $title = $profile->first_name . ' ' . $profile->last_name;
                $description = self::USER_FEED_DESCRIPTION_PREFIX . $profile->first_name . ' ' . $profile->last_name;
            } else {
                throw new Exception("Invalid user or group identifier", 400);
            }
        }

        $wall_response = $this->getContent($connector, "wall.get");
        if (property_exists($wall_response, 'error')) {
            throw new APIError($wall_response->error->error_msg,
                $wall_response->error->error_code,
                $connector->getLastUrl());
        }

        $feed = new FeedWriter(RSS2);
        $id = $this->domain ? $this->domain :
            ($this->owner_id > 0 ? 'id' . $this->owner_id : 'club' . abs($this->owner_id));

        $feed->setTitle($title);
        $feed->setDescription($description);
        $feed->setLink('https://vk.com/' . $id);

        $feed->setChannelElement('language', 'ru-ru');
        $feed->setChannelElement('pubDate', date(DATE_RSS, time()));

        foreach (array_slice($wall_response->response, 1) as $post) {
            $new_item = $feed->createNewItem();
            $new_item->setLink("http://vk.com/wall{$post->to_id}_{$post->id}");
            $new_item->addElement('guid', "http://vk.com/wall{$post->to_id}_{$post->id}");
            $new_item->setDate($post->date);
            $description = $post->text === '' ? array() : array($post->text);
            if (isset($post->copy_text)) { # attached text to re-post
                array_unshift($description, $post->copy_text);
            }
            if (isset($post->attachment->photo->text) && $post->attachment->photo->text !== '') {
                array_push($description, $post->attachment->photo->text);
            }
            if (isset($post->attachment->video->text) && $post->attachment->video->text !== '') {
                array_push($description, $post->attachment->video->text);
            }
            if (isset($post->attachment->link)) {
                array_push($description,
                           $post->attachment->link->title . '<br/>' . $post->attachment->link->description);
            }
            if (!is_null($this->include) && preg_match('/' . $this->include . '/iu', $description) !== 1) {
                continue;
            }
            if (!is_null($this->exclude) && preg_match('/' . $this->exclude . '/iu', $description) !== 0) {
                continue;
            }


            if (isset($post->attachments)) {
                foreach ($post->attachments as $attachment) {
                    switch ($attachment->type) {
                        case 'photo': {
                            array_push($description, "<br><img src='{$attachment->photo->src_big}'/>");
                            break;
                        }
                        /*case 'audio': {
                          $description .= "<br><a href='http://vk.com/wall{$owner_id}_{$post->id}'>{$attachment->audio->performer} &ndash; {$attachment->audio->title}</a>";
                          break;
                        }*/
                        case 'doc': {
                            array_push($description, "<br><a href='{$attachment->doc->url}'>{$attachment->doc->title}</a>");
                            break;
                        }
                        case 'link': {
                            array_push($description, "<br><a href='{$attachment->link->url}'>{$attachment->link->title}</a>");
                            break;
                        }
                        /*case 'video': {
                          $description .= "<br><a href='http://vk.com/video{$attachment->video->owner_id}_{$attachment->video->vid}'><img src='{$attachment->video->image_big}'/></a>";
                          break;
                        }*/
                    }
                }
            }

            foreach ($description as &$paragraph) {
                $paragraph = preg_replace('/\[[^|]+\|([^\]]+)\]/u', '$1', $paragraph); // remove internal vk links like [id123|Name]
            }
            $new_item->setDescription(implode(self::VERTICAL_DELIMITER, $description));
            $text_content = implode('<br/>', $description);
            $new_item->addElement('title', $this->getTitle($text_content));

            $hash_tags = array();
            preg_match_all('/' . self::HASH_TAG_REGEX . '/u', $text_content, $hash_tags);

            foreach ($hash_tags[1] as $hash_tag) {
                $new_item->addElement('category', $hash_tag);
            }

            $feed->addItem($new_item);
        }

        $feed->generateFeed();
        mb_internal_encoding($outer_encoding);
    }

}
