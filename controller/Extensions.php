<?php

namespace atomar\controller;

use atomar\core\Auth;
use atomar\core\Controller;
use atomar\Atomar;
use atomar\hook\Install;
use atomar\hook\Permission;
use model\Extension;

/**
 * TODO: we need to finish updating this class.
 * We need to handle what happens when a dependency is missing.
 * Class AdminExtensions
 * @package atomar\controller
 */
class Extensions extends Controller {

    function GET($matches = array()) {
        Auth::authenticate('administer_extensions');

        // search for extensions
        $ext_path = Atomar::extension_dir();
        $files = scandir($ext_path);
        $extensions = array();
//        $extension_names = array();
        foreach ($files as $f) {
            if ($f != '.' && $f != '..' && is_dir($ext_path . $f)) {
                // load extension
                $ext = Atomar::loadModule($ext_path . $f, $f);
                $ext = $this->prepareModule($ext);

//                $extension_names[] = $ext->name;

                $extensions[$ext->slug] = $ext;
            }
        }

        // evaluate dependencies
        $rendered_extensions = array();
        foreach($extensions as $ext) {
            $dependencies = array();
            if(isset($ext->dependencies) && strlen($ext->dependencies)) {
                foreach (explode(',', $ext->dependencies) as $d) {
                    $exists = isset($extensions[$d]);
                    $enabled = $exists ? $extensions[$d]->is_enabled : '0';
                    $dependencies[] = array(
                        'slug' => $d,
                        'exists' => $exists ? '1' : '0',
                        'is_enabled' => $enabled
                    );
                }
            }
            $rendered_extensions[$ext->slug] = $ext->export();
            $rendered_extensions[$ext->slug]['dependencies'] = $dependencies;
        }

        // clean out old extensions
        $valid_names = array_keys($extensions);
        $valid_names[] = Atomar::application_namespace();
        \R::exec('DELETE FROM `extension` WHERE `name` NOT IN (' . \R::genSlots($valid_names) . ') ', $valid_names);

        // render view
        echo $this->renderView('admin/extensions.html', array(
            'extensions' => $rendered_extensions,
            'ext_dir' => Atomar::extension_dir(),
            'app' => Atomar::getAppInfo()
        ));
    }

    /**
     * Enables a single module if possible
     *
     * @param Extension $module the db record of the module to be enabled
     * @return Extension the module
     */
    private function prepareModule($module) {
        if ($module !== null) {

            // check dependencies
//            $dependencies = array();
//            foreach($module->sharedExtensionList as $key => $dependency) {
//                $dependencies[] = array(
//                    'slug' => $dependency->slug,
//                    'exists' => '1',
//                    'is_enabled' => $dependency->is_enabled
//                );
//            }

            if ($module->installed_version && $module->is_enabled) {
                // check for updates
                if (vercmp($module->version, $module->installed_version) == 1) {
                    // TODO: change these to 'has_update'
                    $module->is_update_pending = '1';
                } else {
                    $module->is_update_pending = '0';
                }
            }

            // check supported core versions
            if (!$module->atomar_version || vercmp($module->atomar_version, Atomar::version()) >= 0) {
                $module->is_supported = '1';
            } else {
                $module->is_supported = '0';
            }
            store($module);
        }
        return $module;
    }

    function POST($matches = array()) {
        $extensions = $_POST['extensions'];
        $is_missing_dependencies = false;
        $not_supported = false;
        // disable all extensions
        \R::exec('UPDATE extension SET is_enabled=\'0\' where slug not in (?)', array(Atomar::application_namespace()));
        // process extensions
        if (isset($extensions)) {
            foreach ($extensions as $id => $state) {
                $ext = \R::load('extension', $id);

                // check if supported
                if ($ext->atomar_version && vercmp($ext->atomar_version, Atomar::version()) == -1) {
                    $not_supported = true;
                }

                if (!$this->enableModule($ext->id)) {
                    $is_missing_dependencies = true;
                }
            }
        }

        // install extensions
        Atomar::hook(new Install());

        // rebuild extension permissions
        Atomar::hook(new Permission());

        if ($is_missing_dependencies) {
            set_error('Some extensions could not be enabled because they are missing dependencies.');
        }
        if ($not_supported) {
            set_error('Some extensions could not be enabled because they are not supported.');
        }
        $this->go('/atomar/extensions');
    }

    // stores an extension in the db and saves it in the cache

    private function enableModule($id) {
        $extension = \R::load('extension', $id); //\R::load('extension', $id);

        // validate dependencies
        $required_extensions = array();
        $missing_dependencies = array();
        if($extension->dependencies) {
            $dependencies = explode(',', trim($extension->dependencies));
            $missing_dependencies = array_flip($dependencies);
            $required_extensions = \R::find('extension', 'slug IN (' . \R::genSlots($dependencies) . ') ', $dependencies);
        }

        if ($required_extensions) {
            // check if missing
            foreach ($required_extensions as $required_ext) {
                unset($missing_dependencies[$required_ext->slug]);
            }
            if (count($missing_dependencies) > 0) {
                set_notice('missing dependencies');
                return false;
            }

            // enable dependencies
            foreach ($required_extensions as $ext) {
                if (!$this->enableModule($ext->id)) {
                    // disable the extension
                    $extension->is_enabled = '0';
                    \R::store($extension);
                    return false;
                }
            }
        }

        // Enable extension.
        $extension->is_enabled = '1';
        return \R::store($extension);
    }
}