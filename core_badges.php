<?php

/**
 * Enhanced badges renderer.
 *
 * @author Luke Carrier <luke.carrier@floream.com>
 * @copyright 2015 Floream Limited
 */

defined('MOODLE_INTERNAL') || die;

require_once "{$CFG->dirroot}/badges/renderer.php";

class theme_xxx_core_badges_renderer extends core_badges_renderer {
    /**
     * Badges core component name.
     *
     * We need this for string lookups, which we have a utility method for.
     *
     * @var string
     */
    const MOODLE_COMPONENT = 'badges';

    /**
     * SQL query to select a single CM's module name.
     *
     * This is necessary to determine the appropriate module type, which in turn
     * determines the table in which the module's name is stored.
     *
     * @var string
     */
    const MODULE_INSTANCE_SQL = <<<SQL
SELECT module.name
FROM {course_modules} course_module
INNER JOIN {modules} module
    ON module.id = course_module.module
WHERE course_module.id = ?
SQL;

    /**
     * Render an issued badge.
     *
     * No functional changes, but this override is required due to incorrect use
     * of the self:: scope (as opposed to static:: or $this->) in core.
     *
     * @param \issued_badge $issuedbadge
     *
     * @return string
     */
    protected function render_issued_badge(issued_badge $issuedbadge) {
        global $CFG, $DB, $SITE, $USER;

        $badge = new badge($issuedbadge->badgeid);
        $now = time();

        $table = new html_table();
        $table->id = 'issued-badge-table';

        $imagetable = new html_table();
        $imagetable->attributes = array('class' => 'clearfix badgeissuedimage');
        $imagetable->data[] = array(html_writer::empty_tag('img', array('src' => $issuedbadge->badgeclass['image'])));
        if ($USER->id == $issuedbadge->recipient->id && !empty($CFG->enablebadges)) {
            $imagetable->data[] = array($this->output->single_button(
                        new moodle_url('/badges/badge.php', array('hash' => $issuedbadge->issued['uid'], 'bake' => true)),
                        get_string('download'),
                        'POST'));
            $expiration = isset($issuedbadge->issued['expires']) ? $issuedbadge->issued['expires'] : $now + 86400;
            if (!empty($CFG->badges_allowexternalbackpack) && ($expiration > $now) && badges_user_has_backpack($USER->id)) {
                $assertion = new moodle_url('/badges/assertion.php', array('b' => $issuedbadge->issued['uid']));
                $action = new component_action('click', 'addtobackpack', array('assertion' => $assertion->out(false)));
                $attributes = array(
                        'type'  => 'button',
                        'id'    => 'addbutton',
                        'value' => get_string('addtobackpack', 'badges'));
                $tobackpack = html_writer::tag('input', '', $attributes);
                $this->output->add_action_handler($action, 'addbutton');
                $imagetable->data[] = array($tobackpack);
            }
        }

        $datatable = new html_table();
        $datatable->attributes = array('class' => 'badgeissuedinfo');
        $datatable->colclasses = array('bfield', 'bvalue');

        // Recipient information.
        $datatable->data[] = array($this->output->heading(get_string('recipientdetails', 'badges'), 3), '');
        if ($issuedbadge->recipient->deleted) {
            $strdata = new stdClass();
            $strdata->user = fullname($issuedbadge->recipient);
            $strdata->site = format_string($SITE->fullname, true, array('context' => context_system::instance()));
            $datatable->data[] = array(get_string('name'), get_string('error:userdeleted', 'badges', $strdata));
        } else {
            $datatable->data[] = array(get_string('name'), fullname($issuedbadge->recipient));
        }

        $datatable->data[] = array($this->output->heading(get_string('issuerdetails', 'badges'), 3), '');
        $datatable->data[] = array(get_string('issuername', 'badges'), $badge->issuername);
        if (isset($badge->issuercontact) && !empty($badge->issuercontact)) {
            $datatable->data[] = array(get_string('contact', 'badges'), obfuscate_mailto($badge->issuercontact));
        }
        $datatable->data[] = array($this->output->heading(get_string('badgedetails', 'badges'), 3), '');
        $datatable->data[] = array(get_string('name'), $badge->name);
        $datatable->data[] = array(get_string('description', 'badges'), $badge->description);

        if ($badge->type == BADGE_TYPE_COURSE && isset($badge->courseid)) {
            $coursename = $DB->get_field('course', 'fullname', array('id' => $badge->courseid));
            $datatable->data[] = array(get_string('course'), $coursename);
        }

        $datatable->data[] = array(get_string('bcriteria', 'badges'), $this->print_badge_criteria($badge));
        $datatable->data[] = array($this->output->heading(get_string('issuancedetails', 'badges'), 3), '');
        $datatable->data[] = array(get_string('dateawarded', 'badges'), userdate($issuedbadge->issued['issuedOn']));
        if (isset($issuedbadge->issued['expires'])) {
            if ($issuedbadge->issued['expires'] < $now) {
                $cell = new html_table_cell(userdate($issuedbadge->issued['expires']) . get_string('warnexpired', 'badges'));
                $cell->attributes = array('class' => 'notifyproblem warning');
                $datatable->data[] = array(get_string('expirydate', 'badges'), $cell);

                $image = html_writer::start_tag('div', array('class' => 'badge'));
                $image .= html_writer::empty_tag('img', array('src' => $issuedbadge->badgeclass['image']));
                $image .= $this->output->pix_icon('i/expired',
                                get_string('expireddate', 'badges', userdate($issuedbadge->issued['expires'])),
                                'moodle',
                                array('class' => 'expireimage'));
                $image .= html_writer::end_tag('div');
                $imagetable->data[0] = array($image);
            } else {
                $datatable->data[] = array(get_string('expirydate', 'badges'), userdate($issuedbadge->issued['expires']));
            }
        }

        // Print evidence.
        $agg = $badge->get_aggregation_methods();
        $evidence = $badge->get_criteria_completions($issuedbadge->recipient->id);
        $eids = array_map(create_function('$o', 'return $o->critid;'), $evidence);
        unset($badge->criteria[BADGE_CRITERIA_TYPE_OVERALL]);

        $items = array();
        foreach ($badge->criteria as $type => $criteria) {
            if (in_array($criteria->id, $eids)) {
                $items[] = $this->print_badge_criteria_single($badge, $agg,
                                                              $type, $criteria);
            }
        }

        $datatable->data[] = array(get_string('evidence', 'badges'),
                get_string('completioninfo', 'badges') .
                html_writer::alist($items, array(), 'ul'));
        $table->attributes = array('class' => 'generalbox boxaligncenter issuedbadgebox');
        $table->data[] = array(html_writer::table($imagetable), html_writer::table($datatable));
        $htmlbadge = html_writer::table($table);

        return $htmlbadge;
    }

    /**
     * Print badge overview.
     *
     * No functional changes, but this override is required due to incorrect use
     * of the self:: scope (as opposed to static:: or $this->) in core.
     *
     * @param \badge   $badge
     * @param \context $context
     */
    public function print_badge_overview($badge, $context) {
        $detailstable = new html_table();
        $detailstable->attributes = array('class' => 'clearfix', 'id' => 'badgedetails');
        $detailstable->data[] = array(get_string('name') . ":", $badge->name);
        $detailstable->data[] = array(get_string('description', 'badges') . ":", $badge->description);
        $detailstable->data[] = array(get_string('createdon', 'search') . ":", userdate($badge->timecreated));
        $detailstable->data[] = array(get_string('badgeimage', 'badges') . ":",
                print_badge_image($badge, $context, 'large'));

        $issuertable = new html_table();
        $issuertable->attributes = array('class' => 'clearfix', 'id' => 'badgeissuer');
        $issuertable->data[] = array(get_string('issuername', 'badges') . ":", $badge->issuername);
        $issuertable->data[] = array(get_string('contact', 'badges') . ":",
                html_writer::tag('a', $badge->issuercontact, array('href' => 'mailto:' . $badge->issuercontact)));

        $expiry = '';
        if ($badge->can_expire()) {
            if ($badge->expiredate) {
                $expiry .= get_string('expiredate', 'badges', userdate($badge->expiredate));
            } else if ($badge->expireperiod) {
                if ($badge->expireperiod < 60) {
                    $expiry .= get_string('expireperiods', 'badges', round($badge->expireperiod, 2));
                } else if ($badge->expireperiod < 60 * 60) {
                    $expiry .= get_string('expireperiodm', 'badges', round($badge->expireperiod / 60, 2));
                } else if ($badge->expireperiod < 60 * 60 * 24) {
                    $expiry .= get_string('expireperiodh', 'badges', round($badge->expireperiod / 60 / 60, 2));
                } else {
                    $expiry .= get_string('expireperiod', 'badges', round($badge->expireperiod / 60 / 60 / 24, 2));
                }
            }
        } else {
            $expiry .= get_string('noexpiry', 'badges');
        }

        $criteria = '';
        if ($badge->has_criteria()) {
            $criteria .= $this->print_badge_criteria($badge);
        } else {
            $criteria .= get_string('nocriteria', 'badges');
            if (has_capability('moodle/badges:configurecriteria', $context)) {
                $criteria .= $this->output->single_button(
                    new moodle_url('/badges/criteria.php', array('id' => $badge->id)),
                    get_string('addcriteria', 'badges'), 'POST', array('class' => 'activatebadge'));
            }
        }

        $awards = '';
        if (has_capability('moodle/badges:viewawarded', $context)) {
            $awards .= html_writer::start_tag('fieldset', array('class' => 'generalbox'));
            $awards .= html_writer::tag('legend', get_string('awards', 'badges'), array('class' => 'bold'));
            if ($badge->has_awards()) {
                $url = new moodle_url('/badges/recipients.php', array('id' => $badge->id));
                $a = new stdClass();
                $a->link = $url->out();
                $a->count = count($badge->get_awards());
                $awards .= get_string('numawards', 'badges', $a);
            } else {
                $awards .= get_string('noawards', 'badges');
            }

            if (has_capability('moodle/badges:awardbadge', $context)
                    && $badge->has_manual_award_criteria()
                    && $badge->is_active()) {
                $awards .= $this->output->single_button(
                        new moodle_url('/badges/award.php', array('id' => $badge->id)),
                        get_string('award', 'badges'), 'POST', array('class' => 'activatebadge'));
            }
            $awards .= html_writer::end_tag('fieldset');
        }

        return html_writer::start_tag('fieldset', array('class' => 'generalbox'))
             .     html_writer::tag('legend', get_string('badgedetails', 'badges'), array('class' => 'bold'))
             .     html_writer::table($detailstable)
             . html_writer::end_tag('fieldset')
             . html_writer::start_tag('fieldset', array('class' => 'generalbox'))
             .     html_writer::tag('legend', get_string('issuerdetails', 'badges'), array('class' => 'bold'))
             .     html_writer::table($issuertable)
             . html_writer::end_tag('fieldset')
             . html_writer::start_tag('fieldset', array('class' => 'generalbox'))
             .     html_writer::tag('legend', get_string('issuancedetails', 'badges'), array('class' => 'bold'))
             .     $expiry
             . html_writer::end_tag('fieldset')
             . html_writer::start_tag('fieldset', array('class' => 'generalbox'))
             . html_writer::tag('legend', get_string('bcriteria', 'badges'), array('class' => 'bold'))
             .     $criteria
             . html_writer::end_tag('fieldset')
             . $awards;
    }

    /**
     * Print badge criteria.
     *
     * Modelled after core_badges_renderer::print_badge_critera(), except that
     * we dispatch rendering of criteria to our own renderer methods as opposed
     * to calling award_criteria::get_details() wherever possible.
     *
     * @param \badge $badge
     * @param string $short
     *
     * @return string
     */
    public function print_badge_criteria(badge $badge, $short='') {
        $output             = '';
        $aggregationmethods = $badge->get_aggregation_methods();

        if (!$badge->criteria) {
            return static::string('nocriteria');
        } elseif (count($badge->criteria) === 2) {
            if (!$short) {
                $output .= static::string('criteria_descr');
            }
        } else {
            $stringname = 'criteria_descr_' . $short . BADGE_CRITERIA_TYPE_OVERALL;
            $output .= static::string(
                    $stringname,
                    core_text::strtoupper($aggregationmethods[$badge->get_aggregation_method()]));
        }
        unset($badge->criteria[BADGE_CRITERIA_TYPE_OVERALL]);

        $items = array();
        foreach ($badge->criteria as $type => $criteria) {
            $items[] = $this->print_badge_criteria_single($badge,
                                                          $aggregationmethods,
                                                          $type, $criteria,
                                                          $short);
        }

        $output .= html_writer::alist($items, array(), 'ul');

        return $output;
    }

    /**
     * Dispatch criteria rendering to an appropriate place.
     *
     * Dispatch to a theme renderer method to output the details if one
     * exists -- that way we can make changes to the output.
     */
    protected function print_badge_criteria_single($badge, $aggregationmethods,
                                                   $type, $criteria, $short='') {
        switch ($type) {
            case BADGE_CRITERIA_TYPE_ACTIVITY:
                $details = $this->print_badge_criteria_activity($criteria,
                                                                $short);
                break;

            case BADGE_CRITERIA_TYPE_COURSE:
                $details = $this->print_badge_criteria_course($criteria,
                                                              $short);
                break;

            case BADGE_CRITERIA_TYPE_COURSESET:
                $details = $this->print_badge_criteria_courseset($criteria,
                                                                 $short);
                break;

            default:
                $details = $criteria->get_details();
        }

        if (count($criteria->params) === 1) {
            $stringname = 'criteria_descr_single_' . $short . $type;
            $title      = static::string($stringname);
        } else {
            $stringname = 'criteria_descr_' . $short . $type;
            $count      = core_text::strtoupper($aggregationmethods[$badge->get_aggregation_method($type)]);
            $title      = static::string($stringname, $count);
        }

        return $title . $details;
    }

    /**
     * Print details for activity criteria.
     *
     * Modelled after award_criteria_activity::get_details().
     *
     * @param \award_criteria_activity $criteria
     * @param string                   $short
     *
     * @return string
     */
    protected function print_badge_criteria_activity($criteria, $short='') {
        global $DB, $OUTPUT;

        $output = array();
        foreach ($criteria->params as $param) {
            $mod = static::get_mod_instance($param['module']);
            $url = new moodle_url("/mod/{$mod->modname}/view.php",
                                  array('id' => $mod->id));
            if (!$mod) {
                $str = $OUTPUT->error_text(get_string('error:nosuchmod', 'badges'));
            } else {
                $str = html_writer::start_tag('a', array('href' => $url->out_as_local_url(false)))
                     .     html_writer::tag('b', '"' . get_string('modulename', $mod->modname) . ' - ' . $mod->name . '"')
                     . html_writer::end_tag('a');

                if (isset($p['bydate'])) {
                    $str .= get_string('criteria_descr_bydate', 'badges', userdate($p['bydate'], get_string('strftimedate', 'core_langconfig')));
                }
            }
            $output[] = $str;
        }

        if ($short) {
            return implode(', ', $output);
        } else {
            return html_writer::alist($output, array(), 'ul');
        }
    }

    /**
     * Print details for course criteria.
     *
     * Modelled after award_criteria_course::get_details().
     *
     * @param \award_criteria_activity $criteria
     * @param string                   $short
     *
     * @return string
     */
    protected function print_badge_criteria_course($criteria, $short='') {
        global $DB;

        $param = reset($criteria->params);

        $course = $DB->get_record('course', array('id' => $param['course']));
        if (!$course) {
            $str = $OUTPUT->error_text(get_string('error:nosuchcourse', 'badges'));
        } else {
            $options = array('context' => context_course::instance($course->id));
            $url     = new moodle_url('/course/view.php', array('id' => $course->id));

            $str = html_writer::start_tag('a', array('href' => $url->out_as_local_url(false)))
                 .     html_writer::tag('b', '&nbsp;"' . format_string($course->fullname, true, $options) . '"')
                 . html_writer::end_tag('a');

            if (isset($param['bydate'])) {
                $str .= get_string('criteria_descr_bydate', 'badges', userdate($param['bydate'], get_string('strftimedate', 'core_langconfig')));
            }
            if (isset($param['grade'])) {
                $str .= get_string('criteria_descr_grade', 'badges', $param['grade']);
            }
        }
        return $str;
    }

    /**
     * Print details for course set criteria.
     *
     * Modelled after award_criteria_courseset::get_details().
     *
     * @param \award_criteria_courseset $criteria
     * @param string                    $short
     *
     * @return string
     */
    protected function print_badge_criteria_courseset($criteria, $short='') {
        global $DB, $OUTPUT;

        $output = array();
        foreach ($criteria->params as $param) {
            $coursename = $DB->get_field('course', 'fullname', array('id' => $param['course']));
            $url        = new moodle_url('/course/view.php',
                                         array('id' => $param['course']));

            if (!$coursename) {
                $str = $OUTPUT->error_text(get_string('error:nosuchcourse', 'badges'));
            } else {
                $str = html_writer::start_tag('a', array('href' => $url->out_as_local_url(false)))
                     .     html_writer::tag('b', '"' . $coursename . '"')
                     . html_writer::end_tag('a');
                if (isset($param['bydate'])) {
                    $str .= get_string('criteria_descr_bydate', 'badges', userdate($param['bydate'], get_string('strftimedate', 'core_langconfig')));
                }
                if (isset($param['grade'])) {
                    $str .= get_string('criteria_descr_grade', 'badges', $param['grade']);
                }
            }
            $output[] = $str;
        }

        if ($short) {
            return implode(', ', $output);
        } else {
            return html_writer::alist($output, array(), 'ul');
        }
    }

    /**
     * Obtain a language string.
     *
     * @param string $string
     * @param mixed  $a
     *
     * @return string
     */
    protected static function string($string, $a=null) {
        return get_string($string, static::MOODLE_COMPONENT, $a);
    }

    /**
     * Get a course module by its CMID.
     *
     * Modelled after award_criteria_activity::get_mod_instance().
     *
     * @return \stdClass|null
     */
    protected static function get_mod_instance($cmid) {
        global $DB;

        $record = $DB->get_record_sql(static::MODULE_INSTANCE_SQL, array($cmid));
        return ($record) ? get_coursemodule_from_id($record->name, $cmid) : null;
    }
}
