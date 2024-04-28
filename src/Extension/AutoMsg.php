<?php
/** Automsg Task
* Version			: 1.2.0
* copyright 		: Copyright (C) 2024 ConseilGouz. All rights reserved.
* license    		: https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
*
*/

namespace ConseilGouz\Plugin\Task\AutoMsg\Extension;

defined('_JEXEC') or die;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailTemplate;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Model\ArticleModel;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\ParameterType;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

final class AutoMsg extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;
    use TaskPluginTrait;

    /**
     * @var boolean
     * @since 4.1.0
     */
    protected $autoloadLanguage = true;
    /**
     * @var string[]
     *
     * @since 4.1.0
     */
    protected const TASKS_MAP = [
        'automsg' => [
            'langConstPrefix' => 'PLG_TASK_AUTOMSG',
            'form'            => 'automsg',
            'method'          => 'automsg',
        ],
    ];
    protected $myparams;
    protected $pluginParams;
    protected $categories;
    protected $usergroups;
    protected $deny;
    protected $tokens;
    protected $itemtags;
    protected $info_cat;
    protected $tag_img;
    protected $cat_img;
    protected $cat_img_emb;
    protected $cat_emb_img;
    protected $introimg;
    protected $introimg_emb;
    protected $url;
    protected $needCatImg;
    protected $needIntroImg;
    protected $creator;
    protected $articles;
    protected $users;
    /**
     * @inheritDoc
     *
     * @return string[]
     *
     * @since 4.1.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    protected function automsg(ExecuteTaskEvent $event): int
    {
        $this->myparams = $event->getArgument('params');
        $plugin = PluginHelper::getPlugin('content', 'automsg');
        // Check if plugin is enabled
        if ($plugin) {
            // Get plugin params
            $this->pluginParams = new Registry($plugin->params);
            $this->categories = $this->pluginParams->get('categories', array());
            $this->usergroups = $this->pluginParams->get('usergroups', array());
            if (!count($this->usergroups)) {
                return TaskStatus::INVALID_EXIT;
            }
            $this->goMsg();
        }
        return TaskStatus::OK;
    }
    private function goMsg()
    {
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('plg_content_automsg');
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
        ->select($db->quoteName('u.id'))
        ->from($db->quoteName('#__users').' as u ')
        ->join('LEFT', $db->quoteName('#__user_usergroup_map').' as g on u.id = g.user_id')
        ->where($db->quoteName('block') . ' = 0 AND g.group_id IN ('.implode(',', $this->usergroups).')');
        $db->setQuery($query);
        $this->users = (array) $db->loadColumn();
        // check profile automsg
        $query = $db->getQuery(true)
        ->select($db->quoteName('p.user_id'))
        ->from($db->quoteName('#__user_profiles').' as p ')
        ->where($db->quoteName('profile_key') . ' like ' .$db->quote('profile_automsg.%').' AND '.$db->quoteName('profile_value'). ' like '.$db->quote('%Non%'));
        $db->setQuery($query);
        $this->deny = (array) $db->loadColumn();
        $this->users = array_diff($this->users, $this->deny);

        if (empty($this->users)) {
            return true;
        }
        $this->tokens = $this->getAutomsgToken($this->users);
        $this->articles = $this->getArticlesToSend();
        // build message body
        $data = [];
        foreach ($this->articles as $articleid) {
            $model     = new ArticleModel(array('ignore_request' => true));
            $model->setState('params', $this->params);
            $model->setState('list.start', 0);
            $model->setState('list.limit', 1);
            $model->setState('filter.published', 1);
            $model->setState('filter.featured', 'show');
            // Access filter
            $access = ComponentHelper::getParams('com_content')->get('show_noauth');
            $model->setState('filter.access', $access);

            // Ordering
            $model->setState('list.ordering', 'a.hits');
            $model->setState('list.direction', 'DESC');

            $article = $model->getItem($articleid);
            $data[] = $this->oneLine($article);
        }
        if (count($data)) {
            $this->sendEmails($data);
            $this->updateAutoMsgTable();
        }
    }
    private function getAutomsgToken($users)
    {
        $tokens = array();
        foreach ($users as $user) {
            $token = $this->checkautomsgtoken($user);
            if ($token) {// token found
                $tokens[$user] = $token;
            }
        }
        return $tokens;
    }
    private function checkautomsgtoken($userId)
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
        ->select(
            [
                $db->quoteName('profile_value'),
            ]
        )
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :userid')
            ->where($db->quoteName('profile_key') . ' LIKE '.$db->quote('profile_automsg.token'))
            ->bind(':userid', $userId, ParameterType::INTEGER);

        $db->setQuery($query);
        $result = $db->loadResult();
        if ($result) {
            return $result;
        } // automsg token already exists => exit
        // create a token
        $query = $db->getQuery(true)
        ->insert($db->quoteName('#__user_profiles'));
        $token = mb_strtoupper(strval(bin2hex(openssl_random_pseudo_bytes(16))));
        $order = 2;
        $query->values(
            implode(
                ',',
                $query->bindArray(
                    [
                        $userId,
                        'profile_automsg.token',
                        $token,
                        $order++,
                    ],
                    [
                        ParameterType::INTEGER,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::INTEGER,
                    ]
                )
            )
        );
        $db->setQuery($query);
        $db->execute();
        return $token;
    }
    private function getArticlesToSend()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
        ->select('DISTINCT '.$db->quoteName('article_id'))
            ->from($db->quoteName('#__automsg'))
            ->where($db->quoteName('state') . ' = 0');

        $db->setQuery($query);
        $result = $db->loadColumn();
        return $result;
    }
    private function updateAutoMsgTable()
    {
        $db    = $this->getDatabase();
        $date = Factory::getDate();
        $query = $db->getQuery(true)
        ->update($db->quoteName('#__automsg'))
        ->set($db->quoteName('state').'=1,'.$db->quoteName('sent').'='.$db->quote($date->toSql()))
        ->where($db->quoteName('state') . ' = 0');
        $db->setQuery($query);
        $db->execute();
        return true;
    }
    private function oneLine($article)
    {
        $libdateformat = "d/M/Y h:m";
        $msgcreator = $this->pluginParams->get('msgcreator', 0);
        $creatorId = $article->created_by;
        $this->creator = Factory::getApplication()->getIdentity($creatorId);
        $this->url = "<a href='".URI::root()."index.php?option=com_content&view=article&id=".$article->id."' target='_blank'>".Text::_("PLG_CONTENT_AUTOMSG_CLICK")."</a>";
        $this->info_cat = $this->getCategoryName($article->catid);
        $cat_params = json_decode($this->info_cat[0]->params);
        $this->cat_img = "";
        if (isset($cat_params->image) && ($cat_params->image != "")) {
            $img = HTMLHelper::cleanImageURL($cat_params->image);
            $this->cat_img = '<img src="'.URI::root().pathinfo($img->url)['dirname'].'/'.pathinfo($img->url)['basename'].'" />';
        }
        $images  = json_decode($article->images);
        $article->introimg = "";
        if (!empty($images->image_intro)) { // into img exists
            $img = HTMLHelper::cleanImageURL($images->image_intro);
            $article->introimg = '<img src="'.URI::root().pathinfo($img->url)['dirname'].'/'.pathinfo($img->url)['basename'].'" />';
        }
        if (!in_array($creatorId, $this->users) && (!in_array($creatorId, $this->deny))) { // creator not in users array : add it
            $this->users[] = $creatorId;
        }
        $article_tags = self::getArticleTags($article->id);
        $this->itemtags = "";
        foreach ($article_tags as $tag) {
            $this->itemtags .= '<span class="iso_tag_'.$tag->alias.'">'.(($this->itemtags == "") ? $tag->tag : "<span class='iso_tagsep'><span>-</span></span>".$tag->tag).'</span>';
        }
        $data = [
                'creator'   => $this->creator->name,
                'id'        => $article->id,
                'title'     => $article->title,
                'cat'       => $this->info_cat[0]->title,
                'catimg'    => $this->cat_img,
                'date'      => HTMLHelper::_('date', $article->created, $libdateformat),
                'intro'     => $article->introtext,
                'introimg'  => $article->introimg,
                'url'       => $this->url,
                'subtitle'  => '', // not used
                'tags'      => $this->itemtags,
                'featured'  => $article->featured,
            ];
        return $data;
    }
    private function sendEmails($articlesList)
    {
        $app = Factory::getApplication();
        $config = $app->getConfig();

        if ($this->pluginParams->get('log', 0)) { // need to log msgs
            Log::addLogger(
                array('text_file' => 'plg_task_automsg.log.php'),
                Log::ALL,
                array('plg_task_automsg')
            );
        }
        $lang = $app->getLanguage();
        $lang->load('plg_content_automsg');
        foreach ($this->users as $user_id) {
            // Load language for messaging
            $receiver = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($user_id);
            $go = false;
            $unsubscribe = "";
            if ($this->tokens[$user_id]) {
                $unsubscribe = "<a href='".URI::root()."index.php?option=com_automsg&view=automsg&layout=edit&token=".$this->tokens[$user_id]."' target='_blank'>".Text::_('PLG_CONTENT_AUTOMSG_UNSUBSCRIBE')."</a>";
            }
            $data = ['unsubscribe'   => $unsubscribe];
            $mailer = new MailTemplate('plg_task_automsg.asyncmail', $receiver->getParam('language', $app->get('language')));
            $articles = ['articles' => $articlesList];
            $mailer->addTemplateData($articles);
            $mailer->addTemplateData($data);
            $mailer->addRecipient($receiver->email, $receiver->name);

            try {
                $send = $mailer->Send();
            } catch (\Exception $e) {
                if ($this->pluginParams->get('log', 0)) { // need to log msgs
                    Log::add('Erreur ----> Articles : '.$articlesList.' non envoyé à '.$receiver->email.'/'.$e->getMessage(), Log::ERROR, 'plg_task_automsg');
                } else {
                    $app->enqueueMessage($e->getMessage().'/'.$receiver->email, 'error');
                }
                continue; // try next one
            }
            if ($this->pluginParams->get('log', 0) == 2) { // need to log msgs
                Log::add('Article OK : '.$articlesList.' envoyé à '.$receiver->email, Log::DEBUG, 'plg_task_automsg');
            }
        }
    }
    private function getCategoryName($id)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);
        $query->select('*')
        ->from('#__categories ')
        ->where('id = '.(int)$id)
        ;
        $db->setQuery($query);
        return $db->loadObjectList();
    }
    private function getArticleTags($id)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);
        $query->select('tags.title as tag, tags.alias as alias, tags.note as note, tags.images as images, parent.title as parent_title, parent.alias as parent_alias')
        ->from('#__contentitem_tag_map as map ')
        ->innerJoin('#__content as c on c.id = map.content_item_id')
        ->innerJoin('#__tags as tags on tags.id = map.tag_id')
        ->innerJoin('#__tags as parent on parent.id = tags.parent_id')
        ->where('c.id = '.(int)$id.' AND map.type_alias like "com_content%"')
        ;
        $db->setQuery($query);
        return $db->loadObjectList();
    }
    public function cleantext($text)
    {
        $text = str_replace('<p>', ' ', $text);
        $text = str_replace('</p>', ' ', $text);
        $text = strip_tags($text, '<br>');
        $text = trim($text);
        return $text;
    }
}
