<?php

/**
 *	Displays customised media content relating to the parent holder media type.
 *	@author Nathan Glasl <nathan@silverstripe.com.au>
 */

class MediaPage extends SiteTree {

	private static $db = array(
		'ExternalLink' => 'Varchar(255)',
		'Abstract' => 'Text',
		'Date' => 'Datetime'
	);

	private static $has_one = array(
		'MediaType' => 'MediaType'
	);

	private static $has_many = array(
		'MediaAttributes' => 'MediaAttribute'
	);

	private static $many_many = array(
		'Images' => 'Image',
		'Attachments' => 'File',
		'Tags' => 'MediaTag'
	);

	private static $defaults = array(
		'ShowInMenus' => 0
	);

	private static $can_be_root = false;

	private static $allowed_children = 'none';

	private static $default_parent = 'MediaHolder';

	private static $description = 'Blog, Event, News, Publication <strong>or Custom Media</strong>';

	/**
	 *	The default media types and their respective attributes.
	 */

	private static $page_defaults = array(
		'Blog' => array(
			'Author'
		),
		'Event' => array(
			'Start Time',
			'End Time',
			'Location'
		),
		'News' => array(
			'Author'
		),
		'Publication' => array(
			'Author'
		)
	);

	/**
	 *	The custom default media types and their respective attributes.
	 */

	private static $custom_defaults = array(
	);

	/**
	 *	Add default media types with respective attributes.
	 *
	 *	@parameter <{MEDIA_TYPES_AND_ATTRIBUTES}> array(array(string))
	 */

	public static function customise_defaults($objects) {

		// merge nested array

		if(is_array($objects)) {

			// make sure we don't have an invalid entry

			foreach($objects as $temporary) {
				if(!is_array($temporary)) {
					return;
				}
			}

			// a manual array unique since that doesn't work with nested arrays

			$output = array();
			foreach($objects as $type => $attribute) {
				if(!isset(self::$custom_defaults[$type]) && !isset($output[$type]) && ($type !== 'MediaHolder')) {
					$output[$type] = $attribute;

					// add these new media types too

					MediaType::add_default($type);
				}
			}
			self::$custom_defaults = array_merge(self::$custom_defaults, $output);
		}
	}

	/**
	 *	Display appropriate CMS media page fields.
	 */

	public function getCMSFields() {

		$fields = parent::getCMSFields();

		// make sure the media page type matches the parent media holder

		$fields->addFieldToTab('Root.Main', ReadonlyField::create(
			'Type',
			'Type',
			$this->MediaType()->Title
		), 'Title');

		// display a notification if the media holder has mixed children

		$parent = $this->getParent();
		if($parent && $parent->checkMediaHolder()->exists()) {
			Requirements::css(MEDIAWESOME_PATH . '/css/mediawesome.css');
			$fields->addFieldToTab('Root.Main', LiteralField::create(
				'MediaNotification',
				"<p class='mediawesome notification'><strong>Mixed {$this->MediaType()->Title} Holder</strong></p>"
			), 'Type');
		}

		$fields->addFieldToTab('Root.Main', TextField::create(
			'ExternalLink'
		)->setRightTitle('An <strong>optional</strong> redirect URL to the media source'), 'URLSegment');

		// add and configure the date/time field

		$fields->addFieldToTab('Root.Main', $date = DatetimeField::create(
			'Date'
		), 'Content');
		$date->getDateField()->setConfig('showcalendar', true);

		// add the tags field

		$tags = MediaTag::get()->map()->toArray();
		$fields->addFieldToTab('Root.Main', $tagsList = ListboxField::create(
			'Tags',
			'Tags',
			$tags
		), 'Content');
		$tagsList->setMultiple(true);
		if(!$tags) {
			$tagsList->setAttribute('disabled', 'true');
		}

		// add all the custom attribute fields

		if($this->MediaAttributes()->exists()) {
			foreach($this->MediaAttributes() as $attribute) {
				if(strripos($attribute->Title, 'Time') || strripos($attribute->Title, 'Date') || stripos($attribute->Title, 'When')) {
					$fields->addFieldToTab('Root.Main', $custom = DatetimeField::create(
						"{$attribute->ID}_MediaAttribute",
						$attribute->Title,
						$attribute->Content
					), 'Content');
					$custom->getDateField()->setConfig('showcalendar', true);
				}
				else {
					$fields->addFieldToTab('Root.Main', $custom = TextField::create(
						"{$attribute->ID}_MediaAttribute",
						$attribute->Title,
						$attribute->Content
					), 'Content');
				}
				$custom->setRightTitle('Custom <strong>' . strtolower($this->MediaType()->Title) . '</strong> attribute');
			}
		}

		// add and configure the abstract field just before the main media content.

		$fields->addfieldToTab('Root.Main', $abstract = TextareaField::create(
			'Abstract'
		), 'Content');
		$abstract->setRightTitle('A concise summary of the content');
		$abstract->setRows(6);

		// add tabs for attachments and images

		$type = strtolower($this->MediaType()->Title);
		$fields->addFieldToTab('Root.Images', $images = UploadField::create(
			'Images'
		));
		$images->getValidator()->setAllowedExtensions(array('jpg', 'jpeg', 'png', 'gif', 'bmp'));
		$images->setFolderName("media-{$type}/{$this->ID}/images");
		$fields->addFieldToTab('Root.Attachments', $attachments = UploadField::create(
			'Attachments'
		));
		$attachments->setFolderName("media-{$type}/{$this->ID}/attachments");

		// allow customisation of the cms fields displayed

		$this->extend('updateCMSFields', $fields);

		return $fields;
	}

	/**
	 *	Confirm an external link is valid, link this media page type to the parent holder and update any existing media type attribute references.
	 */

	public function onBeforeWrite() {

		parent::onBeforeWrite();

		// give this page a default date if not set

		if(is_null($this->Date)) {
			$this->Date = SS_Datetime::now()->Format('Y-m-d H:i:s');
		}

		// clean up an external url, making sure it exists/is available

		if($this->ExternalLink) {
			if(stripos($this->ExternalLink, 'http') === false) {
				$this->ExternalLink = 'http://' . $this->ExternalLink;
			}
			$file_headers = @get_headers($this->ExternalLink);
			if(!$file_headers || strripos($file_headers[0], '404 Not Found')) {
				$this->ExternalLink = null;
			}
		}

		// save each custom attribute field

		foreach($this->record as $name => $value) {
			if(strrpos($name, 'MediaAttribute')) {
				$ID = substr($name, 0, strpos($name, '_'));
				$attribute = MediaAttribute::get_by_id('MediaAttribute', $ID);
				$attribute->Content = $value;
				$attribute->write();
			}
		}

		// link this page to the parent media holder

		$parent = $this->getParent();
		if($parent) {
			$type = $parent->MediaType();
			if($type->exists()) {
				$this->MediaTypeID = $type->ID;
				$type = $type->Title;
			}
			else {
				$existing = MediaType::get_one('MediaType');
				$parent->MediaTypeID = $existing->ID;
				$parent->write();
				$this->MediaTypeID = $existing->ID;
				$type = $existing->Title;
			}

			$temporary = array();
			foreach(self::$custom_defaults as $default => $attributes) {
				if(isset(self::$page_defaults[$default])) {
					self::$page_defaults[$default] = array_unique(array_merge(self::$page_defaults[$default], $attributes));
				}
				else {
					$temporary[$default] = $attributes;
				}
			}
			$defaults = array_merge(self::$page_defaults, $temporary);

			// add existing attributes to a new media page

			if(!$this->MediaAttributes()->exists()) {

				// grab updated titles if they exist

				$attributes = MediaAttribute::get()->innerJoin('MediaPage', 'MediaAttribute.MediaPageID = MediaPage.ID')->innerJoin('MediaType', 'MediaPage.MediaTypeID = MediaType.ID')->where("MediaType.Title = '" . Convert::raw2sql($type) . "' AND MediaAttribute.LinkID = -1");
				if($attributes->exists()) {

					// grab another of the same attribute with a link id of -1 (should only be one)

					foreach($attributes as $attribute) {
						$new = MediaAttribute::create();
						$new->Title = $attribute->Title;
						$new->LinkID = $attribute->ID;
						$new->MediaPageID = $this->ID;
						$this->MediaAttributes()->add($new);
						$new->write();
					}
				}
				else if(isset($defaults[$type])) {
					foreach($defaults[$type] as $attribute) {

						// create this brand new attribute

						$new = MediaAttribute::create();
						$new->Title = $attribute;
						$new->LinkID = -1;
						$new->MediaPageID = $this->ID;
						$this->MediaAttributes()->add($new);
						$new->write();
					}
				}
			}
		}
	}

	/**
	 *	Permanently retrieve an attribute for a template, even if it has been changed through the CMS.
	 *
	 *	@return string
	 */

	public function getAttribute($title) {
		foreach($this->MediaAttributes() as $attribute) {
			if($attribute->OriginalTitle === $title) {

				// return the attribute object so any variables may be accessed.

				return $attribute;
			}
		}
	}

}

class MediaPage_Controller extends Page_Controller {

	/**
	 *	Render this media page with a custom template if one exists.
	 *	NOTE: They have the name format <MediaPage_News> for example.
	 */

	public function index() {

		// if a custom template for the specific page type has been defined, use this

		$type = $this->data()->MediaType();
		$templates = array();
		if($type->exists()) {
			$templates[] = "{$this->data()->ClassName}_" . str_replace(' ', '', $type->Title);
		}
		$templates[] = $this->data()->ClassName;
		$templates[] = 'Page';
		return $this->renderWith($templates);
	}

}
