<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2019 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\ReleaseSystem\Site\Model;

defined('_JEXEC') or die();

use FOF30\Container\Container;
use FOF30\Date\Date;
use FOF30\Model\DataModel\Collection;
use FOF30\Model\Model;
use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;

class BleedingEdge extends Model
{
	/**
	 * The numeric ID of the BleedingEdge category we're operating on
	 *
	 * @var  int
	 */
	private $category_id;

	/**
	 * The BleedingEdge category we're operating on
	 *
	 * @var  Categories
	 */
	private $category;

	/**
	 * The absolute path to the category's folder
	 *
	 * @var  string
	 */
	private $folder = null;

	/**
	 * Sets the category we are operating on
	 *
	 * @param Categories|integer $cat A category table or a numeric category ID
	 *
	 * @return void
	 */
	protected function setCategory($cat)
	{
		// Initialise
		$this->category = null;
		$this->category_id = null;
		$this->folder = null;

		if ($cat instanceof Categories)
		{
			$this->category = $cat;
			$this->category_id = $cat->id;
		}
		elseif (is_numeric($cat))
		{
			$this->category_id = (int)$cat;
			$container = Container::getInstance('com_ars');
			$this->category = $container->factory->model('Categories')->tmpInstance();
			$this->category->find($this->category_id);
		}

		// Store folder
		$folder = $this->category->directory;

		// If it is stored locally, make sure the folder exists
		if (!\JFolder::exists($folder))
		{
			$folder = JPATH_ROOT . '/' . $folder;

			if (!\JFolder::exists($folder))
			{
				return;
			}
		}

		$this->folder = $folder;
	}

	/**
	 * Scan a bleeding edge category
	 *
	 * @param   Categories  $category  The category to scan
	 *
	 * @return  void
	 */
	public function scanCategory(Categories $category)
	{
		$this->setCategory($category);

		// Can't proceed without a category
		if (empty($this->category))
		{
			return;
		}

		// Can't proceed without a folder
		if (empty($this->folder))
		{
			return;
		}

		// Can't proceed if it's not a bleedingedge category
		if ($this->category->type != 'bleedingedge')
		{
			return;
		}

		$known_folders = array();

		// Make sure published releases do exist
		if (!empty($category->releases))
		{
			foreach ($category->releases as $release)
			{
				if (!$release->published)
				{
					continue;
				}

				$mustScanFolder = true;
				$folder = null;

				$folderName = $this->getReleaseFolder($this->folder, $release->version, $release->alias, $release->maturity);

				if ($folderName === false)
				{
					$mustScanFolder = false;
				}
				else
				{
					$known_folders[] = $folderName;
					$folder          = $this->folder . '/' . $folderName;
				}

				$exists = false;

				if ($mustScanFolder)
				{
					$exists = \JFolder::exists($folder);
				}

				if (!$exists)
				{
					$release->published = 0;

					$tmp = $release->tmpInstance();
					$tmp->load($release->id);
					$tmp->save($release);
				}
				else
				{
					$tmpRelease = $release->tmpInstance();
					$tmpRelease->bind($release);
					$this->checkFiles($tmpRelease);
				}
			}

			/** @var Collection $category->releases */
			$first_release = $category->releases->first();
		}
		else
		{
			$first_release = null;
		}

		$first_changelog = array();

		/** @var Releases $first_release */
		if (is_object($first_release))
		{
			$changelog = $this->folder . '/' . $first_release->alias . '/CHANGELOG';

			$hasChangelog = false;

			if (\JFile::exists($changelog))
			{
				$hasChangelog    = true;
				$first_changelog = @file_get_contents($changelog);
			}

			if ($hasChangelog)
			{
				if (!empty($first_changelog))
				{
					$first_changelog = explode("\n", str_replace("\r\n", "\n", $first_changelog));
				}
				else
				{
					$first_changelog = array();
				}
			}
		}

		// Get a list of all folders
		$allFolders = \JFolder::folders($this->folder);

		if (!empty($allFolders))
		{
			foreach ($allFolders as $folder)
			{
				if (!in_array($folder, $known_folders))
				{
					// Create a new entry
					$notes = '';

					$changelog = $this->folder . '/' . $folder . '/' . 'CHANGELOG';

					$hasChangelog = false;
					$this_changelog = '';

					if (\JFile::exists($changelog))
					{
						$hasChangelog   = true;
						$this_changelog = @file_get_contents($changelog);
					}

					if ($hasChangelog)
					{
						if (!empty($this_changelog))
						{
							$notes = $this->coloriseChangelog($this_changelog, $first_changelog);
						}
					}
					else
					{
						$this_changelog = '';
					}

					$jNow = new Date();

					$alias = ApplicationHelper::stringURLSafe($folder);

					$data = array(
						'id'          => 0,
						'category_id' => $this->category_id,
						'version'     => $folder,
						'alias'       => $alias,
						'maturity'    => 'alpha',
						'description' => '',
						'notes'       => $notes,
						'groups'      => $this->category->groups,
						'access'      => $this->category->access,
						'published'   => 1,
						'created'     => $jNow->toSql(),
					);

					// Before saving the release, call the onNewARSBleedingEdgeRelease()
					// event of ars plugins so that they have the chance to modify
					// this information.

					// -- Load plugins
					PluginHelper::importPlugin('ars');

					// -- Setup information data
					$infoData = array(
						'folder'          => $folder,
						'category_id'     => $this->category_id,
						'category'        => $this->category,
						'has_changelog'   => $hasChangelog,
						'changelog_file'  => $changelog,
						'changelog'       => $this_changelog,
						'first_changelog' => $first_changelog
					);

					// -- Trigger the plugin event
					$app       = Factory::getApplication();
					$jResponse = $app->triggerEvent('onNewARSBleedingEdgeRelease', array(
						$infoData,
						$data
					));

					// -- Merge response
					if (is_array($jResponse))
					{
						foreach ($jResponse as $response)
						{
							if (is_array($response))
							{
								$data = array_merge($data, $response);
							}
						}
					}

					// -- Create the BE release
					/** @var Releases $table */
					$table = $this->container->factory->model('Releases')->tmpInstance();

					try
					{
						$table->create($data);
						$this->checkFiles($table);
					}
					catch (\Exception $e)
					{
					}
				}
			}
		}
	}

	public function checkFiles(Releases $release)
	{
		if (!$release->id)
		{
			throw new \LogicException('Unexpected empty release identifier in BleedingEdge::checkFiles()');
		}

		// Make sure we are given a release which exists
		if (empty($release->category_id))
		{
			return;
		}

		// Set the category from the release if the model's category doesn't match
		if (($this->category_id != $release->category_id) || empty($this->folder))
		{
			$this->setCategory($release->category_id);
		}

		// Make sure the category was indeed set
		if (empty($this->category) || empty($this->category_id) || empty($this->folder))
		{
			return;
		}

		// Can't proceed if it's not a bleedingedge category
		if ($this->category->type != 'bleedingedge')
		{
			return;
		}

		// Safe fallback
		$folderName = $this->getReleaseFolder($this->folder, $release->version, $release->alias, $release->maturity);

		if ($folderName === false)
		{
			// Normally this shouldn't happen!
			return;
		}
		else
		{
			$known_folders[] = $folderName;
			$folder          = $this->folder . '/' . $folderName;
		}
		// Do we have a changelog?
		if (empty($release->notes))
		{
			$changelog = $folder . '/CHANGELOG';
			$hasChangelog = false;
			$this_changelog = '';

			if (\JFile::exists($changelog))
			{
				$hasChangelog   = true;
				$this_changelog = @file_get_contents($changelog);
			}

			if ($hasChangelog)
			{
				$first_changelog = array();
				$notes = $this->coloriseChangelog($this_changelog, $first_changelog);
				$release->notes = $notes;

				$release->save();
			}
		}

		$release->getRelations()->rebase($release);

		$known_items = array();

		$files = \JFolder::files($folder);

		if ($release->items->count())
		{
			/** @var Items $item */
			foreach ($release->items as $item)
			{
				$known_items[] = basename($item->filename);

				if ($item->published && !in_array(basename($item->filename), $files))
				{
					$item->unpublish();
				}

				if (!$item->published && in_array(basename($item->filename), $files))
				{
					$item->publish();
				}
			}
		}

		if (!empty($files))
		{
			foreach ($files as $file)
			{
				if (basename($file) == 'CHANGELOG')
				{
					continue;
				}

				if (in_array($file, $known_items))
				{
					continue;
				}

				$jNow = new Date();
				$data = array(
					'id'          => 0,
					'release_id'  => $release->id,
					'description' => '',
					'type'        => 'file',
					'filename'    => $folderName . '/' . $file,
					'url'         => '',
					'groups'      => $release->groups,
					'hits'        => '0',
					'published'   => '1',
					'created'     => $jNow->toSql(),
					'access'      => '1'
				);

				// Before saving the item, call the onNewARSBleedingEdgeItem()
				// event of ars plugins so that they have the chance to modify
				// this information.
				// -- Load plugins
				PluginHelper::importPlugin('ars');
				// -- Setup information data
				$infoData = array(
					'folder'     => $folder,
					'file'       => $file,
					'release_id' => $release->id,
					'release'    => $release
				);
				// -- Trigger the plugin event
				$app       = Factory::getApplication();
				$jResponse = $app->triggerEvent('onNewARSBleedingEdgeItem', array(
					$infoData,
					$data
				));
				// -- Merge response
				if (is_array($jResponse))
				{
					foreach ($jResponse as $response)
					{
						if (is_array($response))
						{
							$data = array_merge($data, $response);
						}
					}
				}

				if (isset($data['ignore']))
				{
					if ($data['ignore'])
					{
						continue;
					}
				}

				/** @var Items $table */
				$table = $this->container->factory->model('Items')->tmpInstance();
				$table->create($data);
			}
		}

		if (isset($table) && is_object($table) && method_exists($table, 'reorder'))
		{
			$db = $table->getDbo();
			$table->reorder($db->qn('release_id') . ' = ' . $db->q($release->id));
		}
	}

	private function coloriseChangelog(&$this_changelog, $first_changelog = array())
	{
		$this_changelog = explode("\n", str_replace("\r\n", "\n", $this_changelog));

		if (empty($this_changelog))
		{
			return '';
		}

		$notes = '';

		$params = ComponentHelper::getParams('com_ars');

		$generate_changelog = $params->get('begenchangelog', 1);
		$colorise_changelog = $params->get('becolorisechangelog', 1);

		if ($generate_changelog)
		{
			$notes .= '<ul>';

			foreach ($this_changelog as $line)
			{
				if (in_array($line, $first_changelog))
				{
					continue;
				}

				if ($colorise_changelog)
				{
					$notes .= '<li>' . $this->colorise($line) . "</li>\n";
				}
				else
				{
					$notes .= "<li>$line</li>\n";
				}
			}

			$notes .= '</ul>';
		}

		return $notes;
	}

	private function colorise($line)
	{
		$line = trim($line);
		$line_type = substr($line, 0, 1);

		switch ($line_type)
		{
			case '+':
				$style = 'added';
				$line = trim(substr($line, 1));
				break;

			case '-':
				$style = 'removed';
				$line = trim(substr($line, 1));
				break;

			case '#':
				$style = 'bugfix';
				$line = trim(substr($line, 1));
				break;

			case '~':
				$style = 'minor';
				$line = trim(substr($line, 1));
				break;

			case '!':
				$style = 'important';
				$line = trim(substr($line, 1));
				break;

			default:
				$style = 'default';
				break;
		}

		return "<span class=\"ars-devrelease-changelog-$style\">$line</span>";
	}

	private function getReleaseFolder($folder, $version, $alias, $maturity)
	{
		$maturityLower = strtolower($maturity);
		$maturityUpper = strtoupper($maturity);

		$candidates = array(
			$alias,
			$version,
			$version . '_' . $maturityUpper,
			$version . '_' . $maturityLower,
			$alias . '_' . $maturityUpper,
			$alias . '_' . $maturityLower,
		);

		foreach ($candidates as $candidate)
		{
			$folderCheck = $folder . '/' . $candidate;

			if (\JFolder::exists($folderCheck))
			{
				return $candidate;
			}
		}

		return false;
	}
}
