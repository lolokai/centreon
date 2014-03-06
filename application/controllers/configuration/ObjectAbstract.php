<?php
/*
 * Copyright 2005-2014 MERETHIS
 * Centreon is developped by : Julien Mathis and Romain Le Merlus under
 * GPL Licence 2.0.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation ; either version 2 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see <http://www.gnu.org/licenses>.
 *
 * Linking this program statically or dynamically with other modules is making a
 * combined work based on this program. Thus, the terms and conditions of the GNU
 * General Public License cover the whole combination.
 *
 * As a special exception, the copyright holders of this program give MERETHIS
 * permission to link this program with independent modules to produce an executable,
 * regardless of the license terms of these independent modules, and to copy and
 * distribute the resulting executable under terms of MERETHIS choice, provided that
 * MERETHIS also meet, for each linked independent module, the terms  and conditions
 * of the license of that module. An independent module is a module which is not
 * derived from this program. If you modify this program, you may extend this
 * exception to your version of the program, but you are not obliged to do so. If you
 * do not wish to do so, delete this exception statement from your version.
 *
 * For more information : contact@centreon.com
 *
 */

namespace Controllers\Configuration;


/**
 * Abstact class for configuration controller
 *
 * @version 3.0.0
 * @author Maximilien Bersoult <mbersoult@merethis.com>
 */
abstract class ObjectAbstract extends \Centreon\Core\Controller
{
    /**
     * List view for object
     */
    public function listAction()
    {
        /* Init template */
        $di = \Centreon\Core\Di::getDefault();
        $tpl = $di->get('template');

        /* Load CssFile */
        $tpl->addCss('dataTables.css')
            ->addCss('dataTables.bootstrap.css')
            ->addCss('dataTables-TableTools.css')
            ->addCss('select2.css')
            ->addCss('select2-bootstrap.css')
            ->addCss('centreon-wizard.css');

        /* Load JsFile */
        $tpl->addJs('jquery.dataTables.min.js')
            ->addJs('jquery.dataTables.TableTools.min.js')
            ->addJs('bootstrap-dataTables-paging.js')
            ->addJs('jquery.dataTables.columnFilter.js')
            ->addJs('jquery.select2/select2.min.js')
            ->addJs('centreon-wizard.js');

        /* Display variable */
        $tpl->assign('objectName', $this->objectDisplayName);
        $tpl->assign('objectAddUrl', $this->objectBaseUrl . '/add');
        $tpl->assign('objectListUrl', $this->objectBaseUrl . '/list');
        $tpl->assign('objectMcUrl', $this->objectBaseUrl . '/massive_change');
        $tpl->assign('objectMcFieldsUrl', $this->objectBaseUrl . '/mc_fields');
        $tpl->assign('objectDuplicateUrl', $this->objectBaseUrl . '/duplicate');
        $tpl->assign('objectDeleteUrl', $this->objectBaseUrl . '/delete');
        $tpl->display('configuration/list.tpl');
    }

    /**
     * Action for getting list of objects
     *
     * Response JSON
     */
    abstract public function formListAction();

    public function datatableAction()
    {
        $di = \Centreon\Core\Di::getDefault();
        $router = $di->get('router');
        
        $router->response()->json(\Centreon\Core\Datatable::getDatas(
            $this->objectName,
            $this->getParams('get')
            )
        );
    }

    /**
     * Get wizard for add a object
     *
     * Response HTML
     */
    public function addAction()
    {
        $form = new \Centreon\Core\Form\Wizard($this->objectBaseUrl . '/add', 0, array('id' => 0));
        echo $form->generate();
    }

    /**
     * Get the list of massive change fields
     *
     * Response JSON
     */
    public function getMassiveChangeFieldsAction()
    {
        $di = \Centreon\Core\Di::getDefault();
        $router = $di->get('router');
        $dbconn = $di->get('db_centreon');

        $data = array(
            'listMc' => array()
        );

        $stmt = $dbconn->prepare("SELECT f.field_id, f.label
            FROM form_field f, form_massive_change_field_relation mcfr, form_massive_change mc
            WHERE mc.route = :route
                AND mc.massive_change_id = mcfr.massive_change_id
                AND f.field_id = mcfr.field_id");
        $stmt->bindValue(':route', $this->objectBaseUrl . '/mc_fields', \PDO::PARAM_STR);
        $stmt->execute();
        while ($row = $stmt->fetch()) {
            $data['listMc'][$row['field_id']] = $row['label'];
        }

        $router->response()->json($data);
    }

    /**
     * Get field HTML
     *
     * Response HTML
     */
    public function getMcFieldAction()
    {
        $di = \Centreon\Core\Di::getDefault();
        $router = $di->get('router');
        $dbconn = $di->get('db_centreon');
        $tpl = $di->get('template');
        
        $requestParam = $this->getParams('named');

        $stmt = $dbconn->prepare("SELECT name, label, default_value, attributes, type, help
            FROM form_field
            WHERE field_id = :id");
        $stmt->bindValue(':id', $requestParam['id'], \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        $form = new \Centreon\Core\Form('default');
        $form->add($row, array('id' => 0));
        $formElements = $form->toSmarty();
        $tpl->assign('field', $formElements[$row['name']]['html']);
        $tpl->display('tools/mcField.tpl');
    }

    /**
     * Duplicate a object
     *
     * Response JSON
     */
    public function duplicateAction()
    {
        $di = \Centreon\Core\Di::getDefault();
        $listDuplicate = json_decode($di->get('router')->request()->param('duplicate'));

        $objClass = $this->objectClass;
        foreach ($listDuplicate as $id => $nb) {
            $objClass::duplicate($id, $nb);
        }
        $di->get('router')->response()->json(array(
            'success' => true
        ));
    }

    /**
     * Apply the massive change to a object
     *
     * Response JSON
     */
    public function massiveChangeAction()
    {
        $di = \Centreon\Core\Di::getDefault();
        $params = $di->get('router')->request()->paramsPost();

        $objClass = $this->objectClass;
        foreach ($params['ids'] as $id) {
            $objClass::update($id, $params['values']);
        }

        $di->get('router')->response()->json(array(
            'success' => true
        ));
    }

    /**
     * Delete a object
     *
     * Response JSON
     */
    public function deleteAction()
    {
        $di = \Centreon\Core\Di::getDefault();
        $params = $di->get('router')->request()->paramsPost();

        $objClass = $this->objectClass;
        foreach ($params['ids'] as $id) {
            $objClass::delete($id);
	    $this->postSave($id, 'delete');
        }

        $di->get('router')->response()->json(array(
            'success' => true
        ));
    }

    /**
     * Action after save
     *
     * * Emit event objectName.action
     *
     * @param $id int The object id
     * @param $action string The action (add, update, delete)
     */
    protected function postSave($id, $action = 'add')
    {
        $di = \Centreon\Core\Di::getDefault();
        $params = $di->get('router')->request()->getParams();
        $event = $di->get('action_hooks');
        $eventParams = array(
            'id' => $id,
            'params' => $params
        );
        $event->emit($this->objectName . '.' . $action, $eventParams);
    } 
}