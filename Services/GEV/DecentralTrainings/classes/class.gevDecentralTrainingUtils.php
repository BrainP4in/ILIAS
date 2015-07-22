<?php

/* Copyright (c) 1998-2014 ILIAS open source, Extended GPL, see docs/LICENSE */#

/**
* Utilities for decentral trainings of Generali.
*
* @author	Richard Klees <richard.klees@concepts-and-training.de>
* @version	$Id$
*/

require_once("Modules/OrgUnit/classes/Types/class.ilOrgUnitType.php");
require_once("Services/GEV/Utils/classes/class.gevAMDUtils.php");
require_once("Services/GEV/Utils/classes/class.gevRoleUtils.php");
require_once("Services/GEV/Utils/classes/class.gevCourseUtils.php");
require_once("Services/GEV/Utils/classes/class.gevSettings.php");
require_once("Services/GEV/DecentralTrainings/classes/class.gevDecentralTrainingSettings.php");
require_once("Services/GEV/DecentralTrainings/classes/class.gevDecentralTrainingException.php");

class gevDecentralTrainingUtils {
	static $instance = null;
	static $creation_request_db = null;
	protected $creation_permissions = array();
	protected $creation_users = array();
	
	protected function __construct() {
		global $ilDB, $ilias, $ilLog, $ilAccess, $tree, $lng, $rbacreview, $rbacadmin, $rbacsystem;
		$this->db = &$ilDB;
		$this->ilias = &$ilias;
		$this->log = &$ilLog;
		$this->access = &$ilAccess;
		$this->tree = &$tree;
		$this->lng = &$lng;
		$this->rbacreview = &$rbacreview;
		$this->rbacadmin = &$rbacadmin;
		$this->rbacsystem = &$rbacsystem;
	}
	
	public static function getInstance() {
		if (self::$instance === null) {
			self::$instance = new gevDecentralTrainingUtils();
		}
		
		return self::$instance;
	}
	
	protected function getOrgTree() {
		require_once("Modules/OrgUnit/classes/class.ilObjOrgUnitTree.php");
		return ilObjOrgUnitTree::_getInstance();
	}
	
	// PERMISSIONS
	
	public function canCreateFor($a_user_id, $a_target_user_id) {
		if (!array_key_exists($a_user_id, $this->creation_permissions)) {
			$this->creation_permissions[$a_user_id] = array();
		}
		
		if (!array_key_exists($a_target_user_id, $this->creation_permissions[$a_user_id])) {
			$this->creation_permissions[$a_user_id][$a_target_user_id] = $this->queryCanCreateFor($a_user_id, $a_target_user_id);
		}
		
		return $this->creation_permissions[$a_user_id][$a_target_user_id];
	}
	
	protected function queryCanCreateFor($a_user_id, $a_target_user_id) {
		
		if ($a_user_id == $a_target_user_id) {
			return count($this->getOrgTree()->getOrgusWhereUserHasPermissionForOperation("add_dec_training_self", $a_user_id)) > 0;
		}
		else {
			return in_array($a_target_user_id, $this->getUsersWhereCanCreateFor($a_user_id));
		}
	}
	
	public function getUsersWhereCanCreateFor($a_user_id) {
		require_once("Services/GEV/Utils/classes/class.gevOrgUnitUtils.php");
		
		if (array_key_exists($a_user_id, $this->creation_users)) {
			return $this->creation_users[$a_user_id];
		}
		
		$orgus_d = $this->getOrgTree()->getOrgusWhereUserHasPermissionForOperation("add_dec_training_others", $a_user_id);
		$orgus_r = $this->getOrgTree()->getOrgusWhereUserHasPermissionForOperation("add_dec_training_others_rec", $a_user_id);
		$orgus_s = gevOrgUnitUtils::getAllChildren($orgus_r);
		foreach ($orgus_s as $key => $value) {
			$orgus_s[$key] = $value["ref_id"];
		}
		
		$orgus = array_unique(array_merge($orgus_d, $orgus_r, $orgus_s));
		
		$this->creation_users[$a_user_id] = gevOrgUnitUtils::getTrainersIn($orgus);
		return $this->creation_users[$a_user_id];
	}
	
	public function canCreate($a_user_id) {
		return	   count($this->getOrgTree()->getOrgusWhereUserHasPermissionForOperation("add_dec_training_self"), $a_user_id) > 0
				|| count($this->getUsersWhereCanCreateFor($a_user_id)) > 0;
	}
	
	// TEMPLATES
	
	protected function templateBaseQuery($a_where = "") {
		require_once("Services/GEV/Utils/classes/class.gevSettings.php");
		
		$ltype_field_id = gevSettings::getInstance()->getAMDFieldId(gevSettings::CRS_AMD_TYPE);
		$edu_prog_field_id = gevSettings::getInstance()->getAMDFieldId(gevSettings::CRS_AMD_EDU_PROGRAMM);
		$is_tmplt_field_id = gevSettings::getInstance()->getAMDFieldId(gevSettings::CRS_AMD_IS_TEMPLATE);
		
		return   "SELECT DISTINCT od.obj_id"
				."              , od.title"
				."              , od.description"
				."              , oref.ref_id"
				."				, ltype.value as ltype"
				."  FROM crs_settings cs"
				."  JOIN object_data od ON od.obj_id = cs.obj_id"
				."  LEFT JOIN object_reference oref "
				."    ON cs.obj_id = oref.obj_id "
				."  LEFT JOIN adv_md_values_text edu_prog"
				."    ON cs.obj_id = edu_prog.obj_id"
				."    AND edu_prog.field_id = ".$this->db->quote($edu_prog_field_id, "integer")
				."  LEFT JOIN adv_md_values_text ltype"
				."    ON cs.obj_id = ltype.obj_id"
				."    AND ltype.field_id = ".$this->db->quote($ltype_field_id, "integer")
				."  LEFT JOIN adv_md_values_text is_template"
				."    ON cs.obj_id = is_template.obj_id"
				."    AND is_template.field_id = ".$this->db->quote($is_tmplt_field_id, "integer")
				." WHERE cs.activation_type = 1"
				."   AND oref.deleted IS NULL"
				."   AND is_template.value = 'Ja'"
				."   AND edu_prog.value = 'dezentrales Training' "
				.$a_where
				." ORDER BY od.title ASC";
	}
	
	public function getAvailableTemplatesFor($a_user_id) {
		require_once("Services/GEV/Utils/classes/class.gevSettings.php");
		
		$ltype_field_id = gevSettings::getInstance()->getAMDFieldId(gevSettings::CRS_AMD_TYPE);
		$edu_prog_field_id = gevSettings::getInstance()->getAMDFieldId(gevSettings::CRS_AMD_EDU_PROGRAMM);
		$is_tmplt_field_id = gevSettings::getInstance()->getAMDFieldId(gevSettings::CRS_AMD_IS_TEMPLATE);
		
		$query = $this->templateBaseQuery();
		$res = $this->db->query($query);
		
		$ret = array();
		while ($rec = $this->db->fetchAssoc($res)) {
			$parent = $this->tree->getParentId($rec["ref_id"]);
			if (   $this->access->checkAccessOfUser($a_user_id, "visible",  "", $rec["ref_id"], "crs")
				&& $this->access->checkAccessOfUser($a_user_id, "copy", "", $rec["ref_id"], "crs")
				&& $this->access->checkAccessOfUser($a_user_id, "create_crs", "", $parent, "cat")) {
				$ret[$rec["obj_id"]] = $rec;
			}
		}
		
		return $ret;
	}
	
	public function getTemplateInfoFor($a_user_id, $a_template_id) {
		$query = $this->templateBaseQuery("  AND od.obj_id = ".$this->db->quote($a_template_id));
		$res = $this->db->query($query);
		if ($rec = $this->db->fetchAssoc($res)) {
			if ($this->access->checkAccessOfUser($a_user_id, "visible",  "", $rec["ref_id"], "crs")) {
				return $rec;
			}
		}
		
		// Could also mean that no permission is granted, but we hide that 
		throw new Exception("gevDecentralTrainingUtils::getTemplateInfoFor: Training not found.");
	}
	
	// Creation Requests Database
	public function getCreationRequestDB() {
		if (self::$creation_request_db === null) {
			require_once("Services/GEV/DecentralTrainings/classes/class.gevDecentralTrainingCreationRequestDB.php");
			self::$creation_request_db = new gevDecentralTrainingCreationRequestDB();
		}
		return self::$creation_request_db;
	}
	
	public function buildScheduleXLS($a_crs_id, $a_send, $a_filename) {
		require_once("Services/GEV/Utils/classes/class.gevCourseUtils.php");
		require_once("Services/User/classes/class.ilObjUser.php");
		
		global $lng;

		if ($a_filename === null) {
			if(!$a_send)
			{
				$a_filename = ilUtil::ilTempnam();
			}
			else
			{
				$a_filename = "uvg_list.xls";
			}
		}

		$lng->loadLanguageModule("common");
		$lng->loadLanguageModule("gev");

		include_once "./Services/Excel/classes/class.ilExcelUtils.php";
		include_once "./Services/Excel/classes/class.ilExcelWriterAdapter.php";
		$adapter = new ilExcelWriterAdapter($a_filename, $a_send);
		$workbook = $adapter->getWorkbook();
		$worksheet = $workbook->addWorksheet();
		$worksheet->setLandscape();

		$columns = array();
		
		$columns[] = $lng->txt("gev_dct_crs_building_block_from");
		$worksheet->setColumn(0, 0, 10);
		$columns[] = $lng->txt("gev_dct_crs_building_block_to");
		$worksheet->setColumn(1, 1, 10);
		$columns[] = $lng->txt("gev_dct_crs_building_block_block");
		$worksheet->setColumn(2, 2, 20);
		$columns[] = $lng->txt("gev_dct_crs_building_block_methods");
		$worksheet->setColumn(3, 3, 16);
		$columns[] = $lng->txt("gev_dct_crs_building_block_media");
		$worksheet->setColumn(4, 4, 16);
		$columns[] = $lng->txt("gev_dct_crs_building_block_content");
		$worksheet->setColumn(5, 5, 22);
		$columns[] = $lng->txt("gev_dct_crs_building_block_lern_dest");
		$worksheet->setColumn(6, 6, 22);

		$format_wrap = $workbook->addFormat();
		$format_wrap->setTextWrap();
		
		$crs_utils = gevCourseUtils::getInstance($a_crs_id);
		$row = $crs_utils->buildListMeta( $workbook
							   , $worksheet
							   , $lng->txt("gev_dec_crs_building_block_title")
							   , ""
							   , $columns
							   , $a_type
							   );
		
		require_once("Services/GEV/Utils/classes/class.gevCourseBuildingBlockUtils.php");
		require_once("Services/GEV/Utils/classes/class.gevObjectUtils.php");
		$blocks = gevCourseBuildingBlockUtils::getAllCourseBuildingBlocks(gevObjectUtils::getRefId($a_crs_id));
		foreach ($blocks as $block) {
			$row++;
			$base = $block->getBuildingBlock();
			$worksheet->write($row, 0, $block->getStartTime(), $format_wrap);
			$worksheet->write($row, 1, $block->getEndTime(), $format_wrap);
			$worksheet->write($row, 2, $base->getTitle(), $format_wrap);
			$worksheet->write($row, 3, implode("\n", $block->getMethods()), $format_wrap);
			$worksheet->write($row, 4, implode("\n", $block->getMedia()), $format_wrap);
			$worksheet->write($row, 5, $base->getContent(), $format_wrap);
			$worksheet->write($row, 6, $base->getLearningDestination(), $format_wrap);
		}
		
		$workbook->close();

		if($a_send)
		{
			exit();
		}

		return array($filename, "Teilnehmer.xls");
 	}
}

?>