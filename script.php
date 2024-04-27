<?php
/**
* Task Automsg Plugin  - Joomla 4.x/5.x Plugin
* Version			: 1.2.0
* copyright 		: Copyright (C) 2024 ConseilGouz. All rights reserved.
* license    		: https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
*/
// No direct access to this file
defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Version;
use Joomla\Component\Mails\Administrator\Model\TemplateModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;

class plgtaskAutomsgInstallerScript
{
    private $min_joomla_version      = '4.1.0';
    private $min_php_version         = '8.0';
    private $name                    = 'Plugin Automsg';
    private $exttype                 = 'plugin';
    private $extname                 = 'automsg';
    private $previous_version        = '';
    private $dir           = null;
    private $db;
    private $lang;
    private $installerName = 'plgtaskautomsginstaller';
    public function __construct()
    {
        $this->dir = __DIR__;
        $this->lang = Factory::getLanguage();
        $this->lang->load($this->extname);
    }

    public function preflight($type, $parent)
    {
        if (! $this->passMinimumJoomlaVersion()) {
            $this->uninstallInstaller();
            return false;
        }

        if (! $this->passMinimumPHPVersion()) {
            $this->uninstallInstaller();
            return false;
        }
        // To prevent installer from running twice if installing multiple extensions
        if (! file_exists($this->dir . '/' . $this->installerName . '.xml')) {
            return true;
        }
    }

    public function postflight($type, $parent)
    {
        if (($type == 'install') || ($type == 'update')) { // remove obsolete dir/files
            $this->postinstall_cleanup();
        }

        switch ($type) {
            case 'install': $message = Text::_('ISO_POSTFLIGHT_INSTALLED');
                break;
            case 'uninstall': $message = Text::_('ISO_POSTFLIGHT_UNINSTALLED');
                break;
            case 'update': $message = Text::_('ISO_POSTFLIGHT_UPDATED');
                break;
            case 'discover_install': $message = Text::_('ISO_POSTFLIGHT_DISC_INSTALLED');
                break;
        }
        return true;
    }
    private function postinstall_cleanup()
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
        // create mail template
        $db = $this->db;
        $query = $db->getQuery(true);
        $query->select('count(`template_id`)');
        $query->from('#__mail_templates');
        $query->where('extension = ' . $db->quote('plg_task_automsg'));
        $db->setQuery($query);
        $result = $db->loadResult();
        if (!$result) {
            $this->create_mail_templates();
        }

        $obsoletes = [
            sprintf("%s/plugins/plg_task_".$this->extname."/automsg.php", JPATH_SITE, $this->extname)
        ];
        foreach ($obsoletes as $file) {
            if (@is_file($file)) {
                File::delete($file);
            }
        }
        $conditions = array(
            $db->qn('type') . ' = ' . $db->q('plugin'),
            $db->qn('folder') . ' = ' . $db->q('task'),
            $db->qn('element') . ' = ' . $db->quote('automsg')
        );
        $fields = array($db->qn('enabled') . ' = 1');

        $query = $db->getQuery(true);
        $query->update($db->quoteName('#__extensions'))->set($fields)->where($conditions);
        $db->setQuery($query);
        try {
            $db->execute();
        } catch (RuntimeException $e) {
            Log::add('unable to enable plugin automsg', JLog::ERROR, 'jerror');
        }

    }
    private function create_mail_templates()
    {
        // check if defined in previous version
        $plugin = PluginHelper::getPlugin('content', 'automsg');
        if ($plugin) { // automsg was defined : get old values
            $params = json_decode($plugin->params);
        }
        $template = new TemplateModel(array('ignore_request' => true));
        $table = $template->getTable();
        $data = [];
        $data['template_id'] = 'plg_task_automsg.asyncmail';
        $data['extension'] = 'plg_task_automsg';
        $data['language'] = '';
        $data['htmlbody'] = '';
        $data['attachments'] = '';
        $data['params'] = '{"tags": ["creator", "title", "cat", "intro", "catimg", "url", "introimg", "subtitle", "tags", "date","featured","unsubscribe"]}';
        $data['subject'] = 'PLG_TASK_AUTOMSG_ASYNC_SUBJECT';
//        if ($plugin && isset($params->asyncline)) {
//            $body = $this->tagstouppercase($params->asyncline);
//            $data['body'] = $body;
//        } else {
        $data['body'] = 'PLG_TASK_AUTOMSG_ASYNC_BODY';
//        }
        $table->save($data);
    }
    private function tagstouppercase($text)
    {
        $pattern = "/\\{(.*?)\\}/i";
        if (preg_match_all($pattern, $text, $matches)) {
            foreach ($matches[0] as $i => $match) {
                $replacement = strtoupper($match);
                $text = str_replace($match, $replacement, $text);
            }
        }
        return $text;
    }

    // Check if Joomla version passes minimum requirement
    private function passMinimumJoomlaVersion()
    {
        $j = new Version();
        $version = $j->getShortVersion();
        if (version_compare($version, $this->min_joomla_version, '<')) {
            Factory::getApplication()->enqueueMessage(
                'Incompatible Joomla version : found <strong>' . $version . '</strong>, Minimum : <strong>' . $this->min_joomla_version . '</strong>',
                'error'
            );

            return false;
        }

        return true;
    }

    // Check if PHP version passes minimum requirement
    private function passMinimumPHPVersion()
    {

        if (version_compare(PHP_VERSION, $this->min_php_version, '<')) {
            Factory::getApplication()->enqueueMessage(
                'Incompatible PHP version : found  <strong>' . PHP_VERSION . '</strong>, Minimum <strong>' . $this->min_php_version . '</strong>',
                'error'
            );
            return false;
        }

        return true;
    }
    private function uninstallInstaller()
    {
        if (! is_dir(JPATH_PLUGINS . '/system/' . $this->installerName)) {
            return;
        }
        $this->delete([
            JPATH_PLUGINS . '/system/' . $this->installerName . '/language',
            JPATH_PLUGINS . '/system/' . $this->installerName,
        ]);
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->delete('#__extensions')
            ->where($db->quoteName('element') . ' = ' . $db->quote($this->installerName))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));
        $db->setQuery($query);
        $db->execute();
        Factory::getCache()->clean('_system');
    }
    public function delete($files = [])
    {
        foreach ($files as $file) {
            if (is_dir($file)) {
                Folder::delete($file);
            }

            if (is_file($file)) {
                File::delete($file);
            }
        }
    }

}
