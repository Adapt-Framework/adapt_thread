<?php
namespace adapt\thread;

/**
 * Interface parent_controller for parents of controller_thread to implement
 * @package adapt\thread
 */
interface parent_controller
{
    /**
     * Asks the parent controller to create a new model for the link table and save it
     * @param int $thread_id
     */
    function spawn_parent($thread_id);

    /**
     * Asks the parent controller to delete its record
     */
    function delete_parent();

    /**
     * Asks the parent controller for the ID of the thread of the current object in scope
     * @return int
     */
    function get_thread_id();
}