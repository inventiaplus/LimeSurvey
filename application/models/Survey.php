<?php

if (!defined('BASEPATH'))
    die('No direct script access allowed');
/*
* LimeSurvey
* Copyright (C) 2007-2011 The LimeSurvey Project Team / Carsten Schmitz
* All rights reserved.
* License: GNU/GPL License v2 or later, see LICENSE.php
* LimeSurvey is free software. This version may have been modified pursuant
* to the GNU General Public License, and as distributed it includes or
* is derivative of works licensed under the GNU General Public License or
* other free or open source software licenses.
* See COPYRIGHT.php for copyright notices and details.
*
* 	$Id: Admin_Controller.php 11256 2011-10-25 13:52:18Z c_schmitz $
*/

class Survey extends CActiveRecord
{
    /**
    * Returns the table's name
    *
    * @access public
    * @return string
    */
    public function tableName()
    {
        return '{{surveys}}';
    }

    /**
    * Returns the table's primary key
    *
    * @access public
    * @return string
    */
    public function primaryKey()
    {
        return 'sid';
    }

	/**
	 * Returns the static model of Settings table
	 *
	 * @static
	 * @access public
     * @param string $class
	 * @return CActiveRecord
	 */
	public static function model($class = __CLASS__)
	{
		return parent::model($class);
	}

    /**
    * Returns this model's relations
    *
    * @access public
    * @return array
    */
    public function relations()
    {
        return array(
        'languagesettings' => array(self::HAS_ONE, 'Surveys_languagesettings', '',
        'on' => 't.sid = languagesettings.surveyls_survey_id AND t.language = languagesettings.surveyls_language'
        ),
        'owner' => array(self::BELONGS_TO, 'User', '', 'on' => 't.owner_id = owner.uid'),
        );
    }

    /**
    * Returns this model's scopes
    *
    * @access public
    * @return array
    */
    public function scopes()
    {
        return array(
        'active' => array(
        'condition' => 'active = "Y"',
        ),
        );
    }

    /**
    * permission scope for this model
    *
    * @access public
    * @param int $loginID
    * @return CActiveRecord
    */
    public function permission($loginID)
    {
        $loginID = (int) $loginID;
        $criteria = $this->getDBCriteria();
        $criteria->mergeWith(array(
        'condition' => 'sid IN (SELECT sid FROM {{survey_permissions}} WHERE uid = :uid AND permission = :permission AND read_p = 1)',
        ));
        $criteria->params[':uid'] = $loginID;
        $criteria->params[':permission'] = 'survey';

        return $this;
    }

    /**
    * Returns additional languages formatted into a string
    *
    * @access public
    * @return array
    */
    public function getAdditionalLanguages()
    {
        $sLanguages = trim($this->additional_languages);
        if ($sLanguages != '')
            return explode(' ', $sLanguages);
        else
            return array();
    }

    /**
     * Returns the additional token attributes
     *
     * @access public
     * @return array
     */
    public function getTokenAttributes()
    {
        // !!! Legacy records support
        if (($attdescriptiondata = @unserialize($this->attributedescriptions)) === false)
        {
            $attdescriptiondata = explode("\n", $this->attributedescriptions);
            $fields = array();
            $languagesettings = array();
            foreach ($attdescriptiondata as $attdescription)
                if (trim($attdescription) != '')
                {
                    $fieldname = substr($attdescription, 0, strpos($attdescription, '='));
                    $desc = substr($attdescription, strpos($attdescription, '=') + 1);
                    $fields[$fieldname] = array(
                        'description' => $desc,
                        'mandatory' => 'N',
                        'show_register' => 'N',
                    );
                    $languagesettings[$fieldname] = $desc;
                }
            $ls = Surveys_languagesettings::model()->findByAttributes(array('surveyls_survey_id' => $this->sid, 'surveyls_language' => $this->language));
            self::model()->updateByPk($this->sid, array('attributedescriptions' => serialize($fields)));
            $ls->surveyls_attributecaptions = serialize($languagesettings);
            $ls->save();
            $attdescriptiondata = $fields;
        }
        return $attdescriptiondata;
    }

    /**
    * !!! Shouldn't this be moved to beforeSave?
    * Creates a new survey - does some basic checks of the suppplied data
    *
    * @param string $data
    * @return mixed
    */
    public function insertNewSurvey($data, $xssfiltering = false)
    {
        do
        {
            if (isset($data['wishSID'])) // if wishSID is set check if it is not taken already
            {
                $data['sid'] = $data['wishSID'];
                unset($data['wishSID']);
            }
            else
                $data['sid'] = sRandomChars(6, '123456789');

            $isresult = self::model()->findByPk($data['sid']);
        }
        while (!is_null($isresult));

        $data['datecreated'] = date("Y-m-d");
        if (isset($data['startdate']) && trim($data['startdate']) == '')
            $data['startdate'] = null;

        if (isset($data['expires']) && trim($data['expires']) == '')
            $data['expires'] = null;

		if($xssfiltering)
		{
			$filter = new CHtmlPurifier();
			$filter->options = array('URI.AllowedSchemes'=>array(
  				'http' => true,
  				'https' => true,
			));
			$data["admin"] = $filter->purify($data["admin"]);
			$data["adminemail"] = $filter->purify($data["adminemail"]);
			$data["bounce_email"] = $filter->purify($data["bounce_email"]);
			$data["faxto"] = $filter->purify($data["faxto"]);
		}

        $survey = new self;
		foreach ($data as $k => $v)
			$survey->$k = $v;
		$survey->save();
        return $data['sid'];
    }

    /**
    * !!! DOESN'T WORK !!!
    *
    * @access public
    * @param int $iSurveyID
    * @param bool @recursive
    * @return void
    */
    public function deleteSurvey($iSurveyID, $recursive=true)
    {
        $this->db->delete('surveys', array('sid' => $iSurveyID));

        if ($recursive)
        {

            $this->load->helper('database');
            $this->load->dbforge();
            $this->load->model('questions_model');
            if (tableExists("survey_{$iSurveyID}"))  //delete the survey_$iSurveyID table
            {
                $dsresult = $this->dbforge->drop_table('survey_' . $iSurveyID) or safe_die("Couldn't drop table survey_" . $iSurveyID);
            }

            if (tableExists("survey_{$iSurveyID}_timings"))  //delete the survey_$iSurveyID_timings table
            {
                $dsresult = $this->dbforge->drop_table('survey_' . $iSurveyID . '_timings') or safe_die("Couldn't drop table survey_" . $iSurveyID . "_timings");
            }

            if (tableExists("tokens_$iSurveyID")) //delete the tokens_$iSurveyID table
            {
                $dsresult = $this->dbforge->drop_table('tokens_' . $iSurveyID) or safe_die("Couldn't drop table token_" . $iSurveyID);
            }

            $oResult = $this->questions_model->getSomeRecords(array('qid'), array('sid' => $iSurveyID));
            foreach ($oResult->result_array() as $aRow)
            {
                $this->db->delete('answers', array('qid' => $aRow['qid']));
                $this->db->delete('conditions', array('qid' => $aRow['qid']));
                $this->db->delete('question_attributes', array('qid' => $aRow['qid']));
            }

            $this->db->delete('questions', array('sid' => $iSurveyID));
            $this->db->delete('assessments', array('sid' => $iSurveyID));
            $this->db->delete('groups', array('sid' => $iSurveyID));
            $this->db->delete('surveys_languagesettings', array('surveyls_survey_id' => $iSurveyID));
            $this->db->delete('survey_permissions', array('sid' => $iSurveyID));
            $this->db->delete('saved_control', array('sid' => $iSurveyID));
            $this->load->model('survey_url_parameters_model');
            $this->survey_url_parameters_model->deleteRecords(array('sid' => $iSurveyID));

            $this->load->model('quota_model');
            $this->quota_model->deleteQuota(array('sid' => $iSurveyID));
        }
    }
}