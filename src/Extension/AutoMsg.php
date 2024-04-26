<?php
/** Automsg Task
* Version			: 1.1.3
* copyright 		: Copyright (C) 2024 ConseilGouz. All rights reserved.
* license    		: https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
*
*/
namespace ConseilGouz\Plugin\Task\AutoMsg\Extension;

defined('_JEXEC') or die;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\Exception\MailDisabledException;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Model\ArticleModel;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Filesystem\Folder;
use Joomla\Filesystem\File;
use Joomla\Registry\Registry;

class AutoMsg extends CMSPlugin implements SubscriberInterface
{
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
    protected $pluginParam,$categories, $usergroups,$deny,$tokens;
    protected $itemtags, $info_cat, $tag_img,$cat_img, $cat_emb_img,
    $introimg,$introimg_emb, $url, $needCatImg,$needIntroImg;
    protected $creator;
    /**
     * Constructor.
     *
     * @param   DispatcherInterface  $dispatcher  The dispatcher
     * @param   array                $config      An optional associative array of configuration settings
     *
     * @since   4.2.0
     */
    public function __construct(DispatcherInterface $dispatcher, array $config)
    {
        parent::__construct($dispatcher, $config);

    }
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

	protected function automsg(ExecuteTaskEvent $event): int {
		$app = Factory::getApplication();
		$this->myparams = $event->getArgument('params');
		$plugin = PluginHelper::getPlugin('content', 'automsg');
		// Check if plugin is enabled
		if ($plugin)
		{
		    // Get plugin params
		    $this->pluginParams = new Registry($plugin->params);
		    $this->categories = $this->pluginParams->get('categories', array());
		    $this->usergroups = $this->pluginParams->get('usergroups', array());
		    $this->goMsg();
		}
		return TaskStatus::OK;		
	}
	protected function goMsg() {
	    $lang = Factory::getLanguage();
	    $lang->load('plg_content_automsg');
	    $db = Factory::getDbo();
	    $query = $db->getQuery(true)
	    ->select($db->quoteName('u.id'))
	    ->from($db->quoteName('#__users').' as u ')
	    ->join('LEFT',$db->quoteName('#__user_usergroup_map').' as g on u.id = g.user_id')
	    ->where($db->quoteName('block') . ' = 0 AND g.group_id IN ('.implode(',',$this->usergroups).')');
	    $db->setQuery($query);
	    $this->users = (array) $db->loadColumn();
	    // check profile automsg
	    $query = $db->getQuery(true)
	    ->select($db->quoteName('p.user_id'))
	    ->from($db->quoteName('#__user_profiles').' as p ')
	    ->where($db->quoteName('profile_key') . ' like ' .$db->quote('profile_automsg.%').' AND '.$db->quoteName('profile_value'). ' like '.$db->quote('%Non%'));
	    $db->setQuery($query);
	    $this->deny = (array) $db->loadColumn();
	    $this->users = array_diff($this->users,$this->deny);
	    
	    if (empty($this->users))
	    {
	        return true;
	    }
	    $this->tokens = $this->getAutomsgToken($this->users);
	    $this->articles = $this->getArticlesToSend();
        // build message body 
        $articlesList = "";
        $cat_img = array();
        $intro_img = array();
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
	        $articlesList .= $this->oneLine($article);
	    }
	    if ($articlesList) {
	       $this->sendEmails($articlesList);
	       $this->updateAutoMsgTable();
	    }
	}
	private function getAutomsgToken($users) {
	    $tokens = array();
	    foreach ($users as $user) {
	        $token = $this->checkautomsgtoken($user);
	        if ($token) {// token found
	            $tokens[$user] = $token;
	        }
	    }
	    return $tokens;
	}
	private function checkautomsgtoken($userId) {
	$db    = Factory::getDbo();
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
	    if ($result) return $result; // automsg token already exists => exit
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
    private function getArticlesToSend() {
        $db    = Factory::getDbo();
        $query = $db->getQuery(true)
        ->select('DISTINCT '.$db->quoteName('article_id'))
            ->from($db->quoteName('#__automsg'))
            ->where($db->quoteName('state') . ' = 0');
            
        $db->setQuery($query);
        $result = $db->loadColumn();
        return $result;
    }
    private function updateAutoMsgTable(){
        $db    = Factory::getDbo();
        $date = Factory::getDate();
        $query = $db->getQuery(true)
        ->update($db->quoteName('#__automsg'))
        ->set($db->quoteName('state').'=1,'.$db->quoteName('sent').'='.$db->quote($date->toSql()))
        ->where($db->quoteName('state') . ' = 0');
        $db->setQuery($query);
        $db->execute();
        return true;
    }
    private function oneLine($article) {
        $msgcreator = $this->pluginParams->get('msgcreator', 0);
        $creatorId = $article->created_by;
        $this->creator = Factory::getUser($creatorId);
        $this->url = "<a href='".URI::root()."index.php?option=com_content&view=article&id=".$article->id."' target='_blank'>".Text::_("PLG_CONTENT_AUTOMSG_CLICK")."</a>";
        $this->info_cat = $this->getCategoryName($article->catid);
        $cat_params = json_decode($this->info_cat[0]->params);
        $this->cat_img[$article->id] = "";
        $this->cat_img_emb[$article->id] = "";
        if ($cat_params->image != "") {
            $this->cat_img[$article->id] = '<img src="cid:catimg'.$article->id.'"  alt="'.$cat_params->image_alt.'" /> ';
            $this->cat_img_emb[$article->id] = $cat_params->image ;
        }
        $images  = json_decode($article->images);
        $this->introimg[$article->id] = "";
        $this->introimg_emb[$article->id] = "";
        if (!empty($images->image_intro)) { // into img exists
            $uneimage = '<img src="cid:introimg'.$article->id.'" alt="'.htmlspecialchars($images->image_intro_alt).'">';
            $this->introimg[$article->id] = $uneimage;
            $this->introimg_emb[$article->id] = $images->image_intro;
        }
        
        $line = $this->pluginParams->get('asyncline', "");
        
        if (!in_array($creatorId,$this->users) && (!in_array($creatorId,$this->deny))) { // creator not in users array : add it
            $this->users[] = $creatorId;
        }
        $line = $this->replaceTags($line,$article);
        return $line.'<br>';
    }
    private function replaceTags($line,$article) {
        $arr_replace= array("{creator}"=>$this->creator->name,"{id}"=>$article->id,"{title}"=>$article->title, "{cat}"=>$this->info_cat[0]->title,"{date}"=>HTMLHelper::_('date', $article->created, $libdateformat), "{intro}" => $article->introtext, "{catimg}" => $this->cat_img[$article->id], "{url}" => $this->url, "{introimg}"=>$this->introimg[$article->id], "{subtitle}" => $article->subtitle, "{tags}" => $itemtags,"{featured}" => $article->featured);
        foreach ($arr_replace as $key_c => $val_c) {
            $line = str_replace($key_c, Text::_($val_c),$line);
        }
        return $line;
    }
    private function sendEmails($articlesList) {
        $config = Factory::getConfig();
        $subject = $this->pluginParams->get('subject','');
        $bodyStd = $this->pluginParams->get('body', '');
        $bodyStd = str_replace('{list}',$articlesList,$bodyStd);
        if ($this->pluginParams->get('log', 0)) { // need to log msgs
            Log::addLogger(
                array('text_file' => 'plg_task_automsg.log.php'),
                Log::ALL,
                array('plg_task_automsg')
            );
        }
        foreach ($this->users as $user_id) {
            // Load language for messaging
            $receiver = Factory::getUser($user_id);
            $go = false;
            $body = $bodyStd;
            if (strpos($body,'{unsubscribe}')) {
                $unsubscribe = "";
                if ($this->tokens[$user_id]) {
                    $unsubscribe ="<a href='".URI::root()."index.php?option=com_automsg&view=automsg&layout=edit&token=".$this->tokens[$user_id]."' target='_blank'>".Text::_('PLG_CONTENT_AUTOMSG_UNSUBSCRIBE')."</a>";
                }
                $body = str_replace('{unsubscribe}',$unsubscribe ,$body);
            }
            $data = $receiver->getProperties();
            $data['fromname'] = $config->get('fromname');
            $data['mailfrom'] = $config->get('mailfrom');
            $data['sitename'] = $config->get('sitename');
            $data['email'] = PunycodeHelper::toPunycode($receiver->get('email'));
            
            $lang = Factory::getLanguage();
            $lang->load('plg_content_automsg');
            $emailSubject = $subject;
            $emailBody = $body;
            $mailer = Factory::getMailer();
            $config = Factory::getConfig();
            $sender = array(
                $config->get( 'mailfrom' ),
                $config->get( 'fromname' )
            );
            $mailer->setSender($sender);
            $mailer->addRecipient($data['email']);
            $mailer->setSubject($emailSubject);
            $mailer->isHtml(true);
            $mailer->Encoding = 'base64';
            $mailer->setBody($emailBody);
            foreach ($this->cat_img_emb as $k =>$i) {
                if ($i) $mailer->AddEmbeddedImage(JPATH_ROOT.'/'.$i,'catimg'.$k);
            }
            foreach ($this->introimg_emb as $k =>$i) {
                if ($i) $mailer->AddEmbeddedImage(JPATH_ROOT.'/'.$i,'introimg'.$k);
            }
            try {
                $send = $mailer->Send();
            } catch (MailDisabledException | phpMailerException $e) {
                if ($this->pluginParams->get('log', 0)) { // need to log msgs
                    Log::add('Erreur ----> Article : '.$article->title.' non envoyé à '.$receiver->get('email').'/'.$e->getMessage(), Log::ERROR, 'plg_content_automsg');
                } else {
                    Factory::getApplication()->enqueueMessage($e->getMessage().'/'.$receiver->get('email'), 'error');
                }
                continue; // try next one
            }
            if ($this->pluginParams->get('log', 0)) { // need to log msgs
                Log::add('Article OK : '.$article->title.' envoyé à '.$receiver->get('email'), Log::DEBUG, 'plg_content_automsg');
            }
        }
    }
    private function getCategoryName($id)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*')
        ->from('#__categories ')
        ->where('id = '.(int)$id)
        ;
        $db->setQuery($query);
        return $db->loadObjectList();
    }
    private function getArticleTags($id) {
        $db = Factory::getDbo();
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
	function cleantext($text)
	{
		$text = str_replace('<p>', ' ', $text);
		$text = str_replace('</p>', ' ', $text);
		$text = strip_tags($text, '<br>');
		$text = trim($text);
		return $text;
	}	
}