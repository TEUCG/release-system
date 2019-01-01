<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2019 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

// no direct access
defined('_JEXEC') or die;

JLoader::import('joomla.plugin.plugin');

/**
 * Editor ARS link buton
 */
class plgButtonArslink extends JPlugin
{
	/**
	 * Constructor
	 *
	 * @access      protected
	 *
	 * @param       object $subject The object to observe
	 * @param       array  $config  An array that holds the plugin configuration
	 *
	 * @since       1.5
	 */
	public function __construct(& $subject, $config)
	{
		parent::__construct($subject, $config);
		$this->loadLanguage();
	}

	/**
	 * Display the button
	 *
	 * @return array A four element array of (article_id, article_title, category_id, object)
	 */
	function onDisplay($name)
	{
		/*
		 * Javascript to insert the link
		 * View element calls arsSelectItem when an item is clicked
		 * arsSelectItem creates the link tag, sends it to the editor,
		 * and closes the select frame.
		 */
		$js = <<<JS


;// This comment is intentionally put here to prevent badly written plugins from causing a Javascript error
// due to missing trailing semicolon and/or newline in their code.
function arsSelectItem(id, title)
{
	var editor = '$name';
	var tag = '<a href='+'\"index.php?option=com_ars&amp;view=Item&amp;task=download&amp;format=raw&amp;id='+id+'\">'+title+'</a>';
	
	/** Use the API, if editor supports it **/
	if (Joomla && Joomla.editors && Joomla.editors.instances && Joomla.editors.instances.hasOwnProperty(editor))
	{
		Joomla.editors.instances[editor].replaceSelection(tag)
	}
	else
    {
		jInsertEditorText(tag, editor);
	}

	jModalClose();
}

JS;

		$doc = JFactory::getDocument();
		$doc->addScriptDeclaration($js);

		$app     = JFactory::getApplication();
		$rootURI = JUri::base();

		if ($app->isAdmin())
		{
			$rootURI .= '../';
		}

		$css = <<<CSS
.button2-left .arsitem {
	background: url($rootURI/media/com_ars/icons/ars_logo_16_bw.png) 100% 0 no-repeat;
}
#editor-xtd-buttons span.icon-arsitem,
i.icon-arsitem {
	display: block;
	float: left;
	width: 16px;
	height: 16px;
	background: url($rootURI/media/com_ars/icons/ars_logo_16_bw.png) 0 0 no-repeat;
}
CSS;

		$doc->addStyleDeclaration($css);

		/*
		 * Use the built-in element view to select the ARS item.
		 * Currently uses blank class.
		 */
		$link =
			'index.php?option=com_ars&amp;view=Items&amp;layout=modal&amp;tmpl=component&amp;' . JSession::getFormToken() . '=1';

		$button          = new JObject;
		$button->modal   = true;
		$button->class   = 'btn';
		$button->link    = $link;
		$button->text    = JText::_('PLG_ARSITEM_BUTTON_ITEM');
		$button->name    = 'arsitem';
		$button->options = "{handler: 'iframe', size: {x: 800, y: 400}}";

		return $button;
	}
}
