<?php
/**
 * @package    System - K2 Youtube import Plugin
 * @version    1.0.0
 * @author     Artem Pavluk - www.art-pavluk.com
 * @copyright  Copyright (c) 2010 - 2018 Private master Pavluk. All rights reserved.
 * @license    GNU/GPL license: http://www.gnu.org/copyleft/gpl.html
 * @link       https://art-pavluk.com
 */

defined('_JEXEC') or die;


use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Registry\Registry;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

class plgSystemK2_youtube_import extends CMSPlugin
{
	/**
	 * Affects constructor behavior. If true, language files will be loaded automatically.
	 *
	 * @var    boolean
	 * @since 1.0.0
	 */
	protected $autoloadLanguage = true;

	public function onAjaxK2_youtube_import()
	{
		$app    = Factory::getApplication();
		$secret = $this->params->get('key');
		$key    = $app->input->get('key', '', 'raw');
		if ($key == $secret)
		{
			if ($total = $this->import())
			{
				// Update time last run
				$this->lastUpdate();

				// Redirect
				$app->enqueueMessage(Text::plural('PLG_SYSTEM_K2_YOUTUBE_IMPORT_SUCCESS', $total));
				$redirect = 'index.php?option=com_k2&view=items';
				if ($app->isSite())
				{
					JLoader::register('K2HelperRoute', JPATH_ROOT . '/components/com_k2/helpers/route.php');
					$redirect = Route::_(K2HelperRoute::getCategoryRoute($this->params->get('category_id')));
				}

				$app->redirect($redirect, true);
			}
		}
		else
		{
			$app->redirect(Uri::base(), true);
		}
	}

	/**
	 * Import videos to K2.
	 *
	 * @param int    $limit
	 * @param string $nextPage
	 *
	 * @return int
	 *
	 * @since 1.0.0
	 * @throws Exception
	 */
	protected function import($limit = 50, $nextPage = null)
	{
		//Get videos ids
		$youtubeData   = $this->send('search', array(
			'type'       => 'video',
			'channelId'  => $this->params->get('youtube_channel'),
			'part'       => 'id',
			'order'      => 'date',
			'maxResults' => $limit,
			'pageToken'  => $nextPage

		));
		$nextPageToken = $youtubeData->get('nextPageToken', false);
		$videoIDs      = $youtubeData->get('items', array());
		$count         = count($videoIDs);
		$total         = $youtubeData->get('pageInfo')->totalResults;

		$ids = array();
		$sql = array();
		$db  = Factory::getDbo();
		foreach ($videoIDs as $videoID)
		{
			$ids[] = $videoID->id->videoId;
			$sql[] = $db->quoteName('video') . ' LIKE ' . $db->quote('%{YouTube}' . $videoID->id->videoId . '{/YouTube}%');

		}

		$query = $db->getQuery(true)
			->select(array('id', 'alias', 'video'))
			->from('#__k2_items')
			->where('(' . implode(' OR ', $sql) . ')');
		$db->setQuery($query);
		$array = $db->loadObjectList();

		$k2Items = array();
		foreach ($array as $row)
		{
			$key           = trim(str_replace(array('{YouTube}', '{/YouTube}'), '', $row->video));
			$k2Items[$key] = $row;
		}

		// Get Videos data
		$youtubeVideosData = $this->send('videos', array('part' => 'snippet', 'id' => implode(',', $ids)));
		if (!empty($youtubeVideosData->get('items', array())))
		{
			Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_k2/tables');

			foreach ($youtubeVideosData->get('items') as $video)
			{
				$date        = HTMLHelper::date($video->snippet->publishedAt, 'Y-m-d H:i:s');
				$description = '';
				if (!empty($video->snippet->description))
				{
					preg_match('/^([^:]+)Follow us:/', $video->snippet->description, $matches);

					if (!empty($matches) && !empty($description = $matches[1]))
					{

						$description = $matches[1];
					}
				}

				$row = Table::getInstance('K2Item', 'Table');

				$item                  = array();
				$item['id']            = (!empty($k2Items[$video->id])) ? $k2Items[$video->id]->id : null;
				$item['alias']         = (!empty($k2Items[$video->id])) ? $k2Items[$video->id]->alias : '';
				$item['title']         = $video->snippet->title;
				$item['text']          = $description;
				$item['catid']         = $this->params->get('category_id');
				$item['videoProvider'] = 'YouTube';
				$item['video']         = $video->id;
				$item['created_by']    = $this->params->get('author_id');
				$item['created']       = $date;
				$item['publish_up']    = $date;
				$item['featured']      = 0;
				$item['published']     = 1;
				$item['access']        = 1;
				$item['language']      = '*';

				if (!$row->bind($item))
				{
					continue;
				}

				$row->introtext = '<p>' . $item['text'] . '</p>';
				$row->video     = '{YouTube}' . $item['video'] . '{/YouTube}';

				if (!$row->check())
				{
					continue;
				}
				if (!$row->store())
				{
					continue;
				}
			}
		}

		// Run recurse
		if ($count == $limit && $nextPageToken && $nextPageToken != $nextPage)
		{
			$this->import($limit, $nextPageToken);
		}

		return $total;
	}

	/**
	 * Method to send api request.
	 *
	 * @param string $method
	 * @param array  $params
	 *
	 * @return mixed
	 *
	 * @throws Exception
	 * @since 1.0.0
	 */
	protected function send($method = '', $params = array())
	{
		if (empty($method))
		{
			throw new Exception('Method cant be empty', 404);
		}

		$url = 'https://www.googleapis.com/youtube/v3/' . $method;

		$query = array('key' => $this->params->get('youtube_key'));
		foreach ($params as $name => $value)
		{
			$query[$name] = $value;
		}

		$url .= '?' . http_build_query($query);
		try
		{
			if ($curl = curl_init())
			{
				curl_setopt($curl, CURLOPT_URL, $url);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
				curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');

				$response = curl_exec($curl);
				curl_close($curl);
			}
			else
			{
				throw new Exception('cant curl_init', 502);
			}

			$response = new Registry($response);


			if (!empty($response->get('error')))
			{
				$error = $response->get('error');
				throw new Exception($error->message, $error->code);
			}

			return $response;
		}
		catch (Exception $e)
		{
			throw new Exception($e->getMessage(), $e->getCode());
		}

	}

	/**
	 * Save last update.
	 *
	 *
	 * @return void
	 *
	 * @since 1.0.0
	 */
	protected function lastUpdate()
	{

		$db    = Factory::getDbo();
		$query = $db->getQuery(true)
			->select('*')
			->from('#__extensions')
			->where($db->quoteName('element') . ' = ' . $db->quote('k2_youtube_import'));
		$db->setQuery($query);
		$plugin         = $db->loadObject();
		$plugin->params = new Registry($plugin->params);
		$plugin->params->set('last_updated', Factory::getDate('now')->toSql());
		$plugin->params = (string) $plugin->params;

		$db->updateObject('#__extensions', $plugin, array('extension_id'));

	}

	public function onBeforeRender()
	{
		$app = Factory::getApplication();

		if ($app->isAdmin())
		{
			if ($app->input->getCmd('option') == 'com_k2' && $app->input->getCmd('view', 'items') == 'items')
			{
				// Get an instance of the Toolbar
				$toolbar = Toolbar::getInstance('toolbar');

				// Add your custom button here
				$key    = $this->params->get('key');
				$url    = 'index.php?option=com_ajax&plugin=k2_youtube_import&group=system&format=raw&key=' . $key;
				$button = '<a href="' . $url . '" class="btn btn-small" target="_blank">'
					. '<span class="icon-youtube text-error" aria-hidden="true"></span>'
					. Text::_('PLG_SYSTEM_K2_YOUTUBE_IMPORT_BUTTON') . '</a>';

				$toolbar->appendButton('Custom', $button, 'youtube');
			}
		}
	}
}