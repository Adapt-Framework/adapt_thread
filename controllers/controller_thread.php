<?php
namespace adapt\thread;

defined('ADAPT_STARTED') or die;

/**
 * Controller for threads
 *
 * @author      Joe Hockaday <jdhockad@hotmail.com>
 * @copyright   2016 Adapt Framework
 * @license     MIT
 */

class controller_thread extends \adapt\controller
{
    /**
     * controller_thread constructor.
     * @param null|mixed $parent
     */
    public function __construct($parent = null)
    {
        parent::__construct($parent);

        // Attempt to load the model if the parent is loaded (ie: a thread exists)
        if ($this->parent->model->is_loaded) {
            $this->model = new model_thread($this->parent->model->thread_id);
        }
    }

    /**
     * Handler for the default view - called when the controller is routed without a
     * specific endpoint. This looks at the result objects and returns the highest response
     * code.
     * @return string
     */
    public function view_default()
    {
        // Check for a response object
        if ($this->response) {
            $last = 0;
            foreach ($this->response as $response) {
                if (isset($response['status']) && is_numeric($response['status']) && $response['status'] > $last) {
                    $last = $response['status'];
                }
            }

            // Check for a flat object
            if (isset($this->response['status']) && is_numeric($this->response['status']) && $this->response['status'] > $last) {
                $last = $this->response['status'];
            }

            // Set the highest response code found
            if ($last > 0) {
                http_response_code($last);
            }

            $this->content_type = 'application/json';

            // Return the response
            return json_encode($this->response);
        }

        // If no response has been found, return empty
        return '[]';
    }

    /**
     * @return bool
     */
    public function permission_view_thread()
    {
        return $this->session->is_logged_in &&
            $this->session->user->has_permission(PERM_USE_THREADS);
    }

    /**
     * @return bool
     */
    public function permission_action_add_post()
    {
        return $this->permission_view_thread();
    }

    /**
     * @return bool
     */
    public function permission_action_delete_post()
    {
        return $this->permission_view_thread();
    }

    /**
     * @return bool
     */
    public function permission_action_delete_thread()
    {
        return $this->permission_view_thread();
    }

    /**
     * Views a single thread
     * @return string
     */
    public function view_thread()
    {
        $this->content_type = 'application/json';

        if (!$this->model->is_loaded) {
            return '[]';
        }

        // Load the posts
        $sql = $this->data_source->sql;

        $sql->select('*')
            ->from('post')
            ->where(
                new sql_and(
                    new sql_cond('thread_id', sql::EQUALS, $this->model->thread_id),
                    new sql_cond('date_deleted', sql::IS, new sql_null())
                )
            )
            ->order_by('date_created');

        $results = $sql->execute()->results();

        // Grab the user data associated with the posts
        $user_ids = array();
        foreach ($results as $result) {
            $user_ids[$result['owner_id']] = $result['owner_id'];
        }
        $user_ids = array_values($user_ids);

        // Get the data from the database about the users involved
        $sql = $this->data_source->sql;
        $sql->select('*')
            ->from('user', 'u')
            ->join(
                'contact',
                'c',
                new sql_cond('u.contact_id', sql::EQUALS, 'c.contact_id')
            )
            ->join(
                'contact_email',
                'e',
                new sql_cond('e.contact_id', sql::EQUALS, 'c.contact_id')
            )
            ->where(
                new sql_and(
                    new sql_cond('u.date_deleted', sql::IS, new sql_null()),
                    new sql_cond('c.date_deleted', sql::IS, new sql_null()),
                    new sql_cond('e.date_deleted', sql::IS, new sql_null()),
                    new sql_cond('u.user_id', sql::IN, '(' . implode(', ', $user_ids) . ')')
                )
            )
            ->order('u.user_id');

        $users = $sql->execute()->results();

        // Loop over the posts and get the data from the user result set
        for ($i = 0; $i < count($results); $i++) {
            $results[$i]['user_info'] = $this->get_user_info($results[$i]['owner_id'], $users);
        }

        $return = $this->model->to_hash();
        $return['posts'] = $results;

        return json_encode($return);
    }

    /**
     * Adds a post to a thread
     * Creates a thread if needed
     */
    public function action_add_post()
    {
        // Check for post body
        if (!$this->request['post'] || $this->request['post'] == '') {
            $this->respond('add_post', ['status' => 'bad_request', 'status_code' => 400, 'errors' => 'No post data set']);
            return;
        }

        // Check for parent record
        if (!$this->parent->model->is_loaded) {
            if (!$this->model->is_loaded) {
                $this->model = new model_thread();
                if (isset($this->request['thread_title'])) {
                    $this->model->title = $this->request['thread_title'];
                }

                $this->model->save();
            }

            $this->parent->spawn_parent($this->model->thread_id);
        }

        // Create the new post
        $post = new model_post();
        $post->thread_id = $this->model->thread_id;
        $post->language_id = $this->session->user->contact->language_id;
        $post->owner_id = $this->session->user->user_id;
        $post->post = $this->request['post'];
        $post->save();
        // Add in additional data about the user on the return
        $post->date_created = date_format(new \DateTime(), 'Y-m-d H:i:s');
        $post = $post->to_hash();
        $post['user_info']['title'] = $this->session->user->contact->title;
        $post['user_info']['forename'] = $this->session->user->contact->forename;
        $post['user_info']['middle_names'] = $this->session->user->contact->middle_names;
        $post['user_info']['surname'] = $this->session->user->contact->surname;
        $post['user_info']['email'] = $this->session->user->contact->email;

        $this->respond('add_post', ['status' => 'success', 'status_code' => 200, 'thread' => $this->model->to_hash(), 'post' => $post]);
    }

    /**
     * Deletes a post from this thread
     */
    public function action_delete_post()
    {
        // Check that an ID has been supplied
        if (!is_numeric($this->request['post_id'])) {
            $this->respond('delete_post', ['status' => 'bad_request', 'status_code' => 400, 'errors' => 'You must supply a post ID']);
            return;
        }

        // Attempt to load the post record
        $post = new model_post($this->request['post_id']);

        // Check that the post record exists, and belongs to our thread
        if (!$post->is_loaded || !$this->model->is_loaded || $post->thread_id != $this->model->thread_id) {
            $this->respond('delete_post', ['status' => 'not_found', 'status_code' => 404, 'errors' => 'Something went wrong']);
            return;
        }

        // Good to proceed
        $post->delete();

        $this->respond('delete_post', ['status' => 'success', 'status_code' => 200]);
    }

    /**
     * Deletes a whole thread
     */
    public function action_delete_thread()
    {
        // Check that an ID has been supplied
        if (!is_numeric($this->request['thread_id'])) {
            $this->respond('delete_thread', ['status' => 'bad_request', 'status_code' => 400, 'errors' => 'You must supply a thread ID']);
            return;
        }

        // Check that we are working on this thread
        if (!$this->model->is_loaded || $this->model->thread_id != $this->request['thread_id']) {
            $this->respond('delete_thread', ['status' => 'not_found', 'status_code' => 404, 'errors' => 'Something went wrong']);
            return;
        }

        // We have a valid thread to delete - first ask the parent to delete
        $this->parent->delete_parent();

        // Then delete the posts
        $sql = $this->data_source->sql;
        $sql->update('post')
            ->set('date_deleted', new sql_now())
            ->where(
                new sql_and(
                    new sql_cond('thread_id', sql::EQUALS, $this->model->thread_id),
                    new sql_cond('date_deleted', sql::IS, new sql_null())
                )
            );
        $sql->execute();

        // Now delete this
        $this->model->delete();

        // Return
        $this->respond('delete_thread', ['status' => 'success', 'status_code' => 200]);
    }

    /**
     * Method to scan the users array and to collate the information for the
     * user_id being requested
     * @param int $user_id
     * @param array $users
     * @return array
     */
    private function get_user_info($user_id, $users)
    {
        // Set up and check for empty
        $return = array();
        if (count($users) == 0) {
            return $return;
        }

        // Loop through the input array looking for the user into
        for ($i = 0; $i < count($users); $i++) {
            if ($users[$i]['user_id'] == $user_id) {
                $return['title'] = $users[$i]['title'];
                $return['forename'] = $users[$i]['forename'];
                $return['middle_names'] = $users[$i]['middle_names'];
                $return['surname'] = $users[$i]['surname'];
                $return['email'][] = $users[$i]['email'];
            }

            // We have gone past the user looked for - hardwire an exit from the loop
            if ($user_id > $users[$i]['user_id']) {
                break;
            }
        }

        return $return;
    }
}