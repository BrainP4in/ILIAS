<?php
/* Copyright (c) 1998-2015 ILIAS open source, Extended GPL, see docs/LICENSE */

require_once 'Services/User/classes/class.ilUserUtil.php';
require_once 'Services/Contact/BuddySystem/classes/class.ilBuddySystem.php';
require_once 'Services/Contact/BuddySystem/classes/class.ilBuddyList.php';
require_once 'Services/Contact/BuddySystem/classes/class.ilBuddySystemLinkButton.php';
require_once 'Services/Contact/BuddySystem/classes/class.ilBuddySystemRelation.php';
require_once 'Services/Contact/BuddySystem/classes/states/class.ilBuddySystemRelationStateFactory.php';
require_once 'Services/Mail/classes/class.ilMailFormCall.php';

/**
 * @ilCtrl_Calls      ilUsersGalleryGUI: ilPublicUserProfileGUI
 * @ilCtrl_isCalledBy ilUsersGalleryGUI: ilCourseMembershipGUI, ilGroupMembershipGUI
 */
class ilUsersGalleryGUI
{
	/**
	 * @var ilUsersGalleryCollectionProvider
	 */
	protected $collection_provider;

	/**
	 * @var ilCtrl
	 */
	protected $ctrl;

	/**
	 * @var $tpl ilTemplate
	 */
	protected $tpl;

	/**
	 * @var ilLanguage
	 */
	protected $lng;

	/**
	 * @var ilObjUser
	 */
	protected $user;

	/**
	 * @var ilRbacSystem
	 */
	protected $rbacsystem;

	/**
	 * @var \ILIAS\UI\Factory
	 */
	protected $factory;

	/**
	 * @var \ILIAS\UI\Renderer
	 */
	protected $renderer;

	/**
	 * ilUsersGalleryGUI constructor.
	 * @param ilUsersGalleryCollectionProvider $collection_provider
	 */
	public function __construct(ilUsersGalleryCollectionProvider $collection_provider)
	{
		/**
		 * @var $DIC ILIAS\DI\Container
		 */
		global $DIC;

		$this->ctrl                = $DIC->ctrl();
		$this->tpl                 = $DIC->ui()->mainTemplate();
		$this->lng                 = $DIC->language();
		$this->user                = $DIC->user();
		$this->rbacsystem          = $DIC->rbac()->system();
		$this->factory             = $DIC->ui()->factory();
		$this->renderer            = $DIC->ui()->renderer();

		$this->collection_provider = $collection_provider;
	}

	/**
	 *
	 */
	public function executeCommand()
	{
		$next_class = $this->ctrl->getNextClass();
		$cmd        = $this->ctrl->getCmd('view');

		switch($next_class)
		{
			case 'ilpublicuserprofilegui':
				require_once 'Services/User/classes/class.ilPublicUserProfileGUI.php';
				$profile_gui = new ilPublicUserProfileGUI(ilUtil::stripSlashes($_GET['user']));
				$profile_gui->setBackUrl($this->ctrl->getLinkTarget($this, 'view'));
				$this->ctrl->forwardCommand($profile_gui);
				break;

			default:
				switch($cmd)
				{
					default:
						$this->$cmd();
						break;
				}
				break;
		}
	}

	/**
	 * Displays the participants gallery
	 */
	protected function view()
	{
		$template = $this->populateTemplate($this->collection_provider->getGroupedCollections());
		$this->tpl->setContent($template->get());
	}

	/**
	 * @param ilObjUser $user
	 * @param array     $sections
	 */
	protected function addContactWidgetSection(ilObjUser $user, array &$sections)
	{
		if(
			ilBuddySystem::getInstance()->isEnabled() &&
			$this->user->getId() != $user->getId() &&
			!$this->user->isAnonymous() &&
			!$user->isAnonymous()
		)
		{

			$contact_btn_html = ilBuddySystemLinkButton::getInstanceByUserId($user->getId())->getHtml();
			if($contact_btn_html)
			{
				$sections[] = $this->factory->legacy($contact_btn_html);
			}
		}
	}

	/**
	 * @param ilUsersGalleryGroup[] $gallery_groups
	 * @return ilTemplate
	 */
	protected function populateTemplate(array $gallery_groups)
	{
		$buddylist = ilBuddyList::getInstanceByGlobalUser();
		$tpl       = new ilTemplate('tpl.users_gallery.html', true, true, 'Services/User');

		require_once 'Services/UIComponent/Panel/classes/class.ilPanelGUI.php';
		$panel = ilPanelGUI::getInstance();
		$panel->setBody($this->lng->txt('no_gallery_users_available'));
		$tpl->setVariable('NO_ENTRIES_HTML', json_encode($panel->getHTML()));

		$groups_with_users = array_filter($gallery_groups, function(ilUsersGalleryGroup $group) {
			return count($group) > 0;
		});
		$groups_with_highlight = array_filter($groups_with_users, function(ilUsersGalleryGroup $group) {
			return $group->isHighlighted();
		});

		if(0 == count($groups_with_users))
		{
			$tpl->setVariable('NO_GALLERY_USERS', $panel->getHTML());
			return $tpl;
		}

		require_once 'Services/UIComponent/Panel/classes/class.ilPanelGUI.php';
		$panel = ilPanelGUI::getInstance();
		$panel->setBody($this->lng->txt('no_gallery_users_available'));
		$tpl->setVariable('NO_ENTRIES_HTML', json_encode($panel->getHTML()));

		require_once 'Services/User/Gallery/classes/class.ilUsersGallerySortedUserGroup.php';
		require_once 'Services/User/Gallery/classes/class.ilUsersGalleryUserCollectionPublicNameSorter.php';

		$cards = [];

		foreach($gallery_groups as $group)
		{
			$group = new ilUsersGallerySortedUserGroup($group, new ilUsersGalleryUserCollectionPublicNameSorter());

			foreach($group as $user)
			{
				$card   = $this->factory->card($user->getPublicName());
				$avatar = $this->factory->image()->standard($user->getAggregatedUser()->getPersonalPicturePath('big'), $user->getPublicName());

				$sections = [];

				if(count($groups_with_highlight) > 0)
				{
					$card = $card->withHighlight($group->isHighlighted());

					if($group->isHighlighted())
					{
						$sections[] = $this->factory->legacy($group->getLabel());
					}
				}

				$this->addContactWidgetSection($user->getAggregatedUser(), $sections);

				if($user->hasPublicProfile())
				{
					$this->ctrl->setParameterByClass('ilpublicuserprofilegui', 'user', $user->getAggregatedUser()->getId());
					$public_profile_url = $this->ctrl->getLinkTargetByClass('ilpublicuserprofilegui', 'getHTML');

					$avatar = $avatar->withAction($public_profile_url);
					$card   = $card->withTitleAction($public_profile_url);
				}

				$card = $card->withImage($avatar)->withSections($sections);

				$cards[] = $card;
			}
		}

		$tpl->setVariable('GALLERY_HTML', $this->renderer->render($this->factory->deck($cards)));

		if($this->collection_provider->hasRemovableUsers())
		{
			$tpl->touchBlock('js_remove_handler');
		}

		return $tpl;
	}
}