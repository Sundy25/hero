<?php

/**
* Content Model
*
* Manages content
*
* @author Electric Function, Inc.
* @package Electric Publisher

*/

class Content_model extends CI_Model
{
	private $cache;
	
	function __construct()
	{
		parent::CI_Model();
	}
	
	/*
	* Create New Content
	*
	* @param int $type
	* @param int $user The ID of the submitting user
	* @param string $title
	* @param string $url_path
	* @param array $topics Array of topic ID's
	* @param array $privileges Array of membergroup ID's to restrict access to
	* @param string $publish_date When to publish the content
	* @param array $custom_fields Generated by custom_fields_model->post_to_array()
	*
	* @return $content_id
	*/
	function new_content ($type, $user, $title = '', $url_path = '', $topics = array(), $privileges = array(), $publish_date = FALSE, $custom_fields = array()) {
		$this->load->model('publish/content_type_model');
		$type = $this->content_type_model->get_content_type($type);
		
		if (empty($type)) {
			return FALSE;
		}
		
		if (!empty($title)) {
			$this->load->helper('clean_string');
			$url_path = (empty($url_path)) ? clean_string($title) : $url_path;
			
			// get a global link ID
			// make sure URL is unique
			$this->load->model('link_model');
			
			$link_id = $this->link_model->new_link($url_path, $topics, $title, $type['singular_name'], 'publish', 'content', 'view');
		}
		
		// prep the date
		if (empty($publish_date)) {
			$publish_date = date('Y-m-d H:i:s');
		}
		elseif (date('Y-m-d',strtotime($publish_date)) == date('Y-m-d')) {
			// publishing today
			$publish_date = date('Y-m-d H:i:s');
		}
		else {
			$publish_date = date('Y-m-d',strtotime($publish_date)) . ' 00:00:00';
		}
		
		// insert it into standard content table first
		$insert_fields = array(
							'link_id' => (isset($link_id)) ? $link_id : 0,
							'content_type_id' => $type['id'],
							'content_is_standard' => (empty($title)) ? '0' : '1',
							'content_title' => $title,
							'content_privileges' => (is_array($privileges) and !in_array(0, $privileges)) ? serialize($privileges) : '',
							'content_date' => $publish_date,
							'content_modified' => date('Y-m-d H:i:s'),
							'user_id' => $user,
							'content_topics' => (is_array($topics) and !empty($topics)) ? serialize($topics) : '',
							'content_hits' => '0'
						);
						
		$this->db->insert('content',$insert_fields);
		$content_id = $this->db->insert_id();
						
		// map to topics
		if (is_array($topics)) {
			foreach ($topics as $topic) {
				if ($topic != '0') {
					$this->db->insert('topic_maps',array('topic_id' => $topic, 'content_id' => $content_id));
				}
			}
		}
		
		// insert it into its own content table
		$insert_fields = array(
							'content_id' => $content_id
						);
						
		if (is_array($custom_fields)) {					
			foreach ($custom_fields as $name => $value) {
				$insert_fields[$name] = $value;
			}
		}
		
		$this->db->insert($type['system_name'], $insert_fields);
		
		return $content_id;
	}
	
	/*
	* Update Content
	*
	* @param int $content_id
	* @param string $title
	* @param string $url_path
	* @param array $topics Array of topic ID's
	* @param array $privileges Array of membergroup ID's to restrict access to
	* @param string $publish_date When to publish the content
	* @param array $custom_fields Generated by custom_fields_model->post_to_array()
	*
	* @return $content_id
	*/
	function update_content ($content_id, $title = '', $url_path = '', $topics = array(), $privileges = array(), $publish_date = FALSE, $custom_fields = array()) {
		$content = $this->get_content($content_id, TRUE);
	
		$this->load->model('publish/content_type_model');
		$type = $this->content_type_model->get_content_type($content['type_id']);
		
		if (!empty($title)) {
			if (empty($url_path)) {
				$this->load->helper('clean_string');
				$url_path = clean_string($title);
			}
			
			// make sure URL is unique (unless it hasn't changed, of course)
			$this->load->model('link_model');
			if ($content['url_path'] != $url_path) {	
				$url_path = $this->link_model->prep_url_path($url_path);
				$url_path = $this->link_model->get_unique_url_path($url_path);
				$this->link_model->update_url($content['link_id'], $url_path);
			}
			$this->link_model->update_title($content['link_id'], $title);
			$this->link_model->update_topics($content['link_id'], $topics);
		}
		
		// prep the date
		if (empty($publish_date) or date('Y-m-d',strtotime($publish_date)) == date('Y-m-d',strtotime($content['date']))) {
			$publish_date = FALSE;
		}
		elseif (date('Y-m-d',strtotime($publish_date)) == date('Y-m-d')) {
			// publishing today
			$publish_date = date('Y-m-d H:i:s');
		}
		else {
			$publish_date = date('Y-m-d',strtotime($publish_date)) . ' 00:00:00';
		}
		
		// update standard content table first
		$update_fields = array(
							'content_title' => $title,
							'content_privileges' => (is_array($privileges) and !in_array(0, $privileges)) ? serialize($privileges) : '',
							'content_modified' => date('Y-m-d H:i:s'),
							'content_topics' => (is_array($topics) and !empty($topics)) ? serialize($topics) : ''
						);
						
		if ($publish_date !== FALSE) {
			$update_fields['content_date'] = $publish_date;
		}
						
		$this->db->update('content',$update_fields,array('content_id' => $content['id']));
						
		// clear topic maps
		$this->db->delete('topic_maps',array('content_id' => $content['id']));
						
		// map to topics
		if (is_array($topics)) {
			foreach ($topics as $topic) {
				if ($topic != '0') {
					$this->db->insert('topic_maps',array('topic_id' => $topic, 'content_id' => $content['id']));
				}
			}
		}
		
		// update its own content table
		$update_fields = array();
						
		if (is_array($custom_fields)) {					
			foreach ($custom_fields as $name => $value) {
				$update_fields[$name] = $value;
			}
		}
		
		if (!empty($update_fields)) {
			$this->db->update($type['system_name'], $update_fields, array('content_id' => $content['id']));
		}
		
		return TRUE;
	}

	/*
	* Delete Content
	*
	* @param int $content_id
	*
	* @return boolean TRUE
	*/
	function delete_content ($content_id) {
		$content = $this->get_content($content_id, TRUE);
		
		$this->load->model('link_model');
		$this->link_model->delete_link($content['link_id']);
		
		$this->load->model('publish/content_type_model');
		$type = $this->content_type_model->get_content_type($content['type_id']);
		
		if (empty($content)) {
			return FALSE;
		}
		
		$this->db->delete('content',array('content_id' => $content_id));
		$this->db->delete($type['system_name'], array('content_id' => $content_id));
		
		return TRUE;
	}
	
	/**
	* Add Hit
	*
	* @param int $content_id
	*
	* @return # of hits
	*/
	function add_hit ($content_id) {
		$return = $this->db->select('content_hits')->where('content_id',$content_id)->from('content')->get();
		
		if (!is_object($return)) {
			return;
		}
		
		$hits = (int)$return->row()->content_hits;
		
		$new_hits = $hits + 1;
		
		$this->db->update('content',array('content_hits' => $new_hits),array('content_id' => $content_id));
		
		return $new_hits;
	}
	
	/**
	* Get Content ID
	*
	* Returns content ID from a URL_path
	*
	* @param $url_path
	* 
	* @return boolean|int The content ID, or FALSE
	*/
	function get_content_id($url_path) {
		$this->db->select('content_id');
		$this->db->where('link_url_path',$url_path);
		$this->db->join('links','content.link_id = links.link_id','inner');
		$result = $this->db->get('content');
		
		if ($result->num_rows() == FALSE) {
			return FALSE;
		}
		
		$content = $result->row_array();
		
		return $content['content_id'];
	}
	
	/**
	* Count Content
	*
	* A query optimized to count the number of items returned
	*
	* Same parameters as get_contents()
	*
	* @return int $count
	*/
	function count_content ($filters = array()) {
		return $this->get_contents($filters, TRUE);
	}
	
	/*
	* Get Content
	*
	* Gets a single piece of content, full data
	*
	* @param int $content_id
	*
	* @return array $content
	*/
	function get_content ($content_id, $allow_future = FALSE) {
		$cache_id = $content_id;
		$cache_id .= ($allow_future == FALSE) ? '_0' : '_1';
		if (isset($this->cache[$cache_id])) {
			return $this->cache[$cache_id];
		}
	
		$filters = array('id' => $content_id);
		
		if ($allow_future == TRUE) {
			$filters['allow_future'] = TRUE;
		}
	
		$content = $this->get_contents($filters);
		
		if (empty($content)) {
			return FALSE;
		}
		
		$this->cache[$cache_id] = $content[0];
		return $content[0];
	}
	
	/*
	* Get Contents
	*
	* Gets content by filters
	* If an ID or Type ID is present in filters, it will retrieve all content data from the specific content table
	*
	* @param date $filters['start_date'] Only content after this date
	* @param date $filters['end_date'] Only content before this date
	* @param string $filters['author_like'] Only content created by this user (by username, text search)
	* @param int $filters['type'] Only content of this type
	* @param string $filters['title']
	* @param int $filters['id']
	* @param int|array $filters['topic'] Single topic ID or array of multiple topics
	* @param int|array $filters['author'] Single author ID or array of multiple authors
	* @param string $filters['keyword'] A keyword to search the content for (only applies if specifying a content type in $filters['type']).  If selected, each element returns a "relevance" datum.
	* @param string $filters['date_format'] The format to return dates in
	* @param boolean $filters['allow_future'] Allow content from the future?  Default: No/FALSE
	* @param string $sort
	* @param string $sort_dir
	* @param int $limit
	* @param int $offset
	* @param boolean $counting Set to TRUE to simplify the query and receive a result suitable for counting total records
	*
	* @return array|boolean Array of content, or FALSE
	*/
	function get_contents ($filters = array(), $counting = FALSE) {
		// if we are going to do a content search, let's get the total content items so that we can decide
		// if this is a LIKE or a FULLTEXT search
		if (isset($filters['keyword']) and isset($filters['type'])) {
			$content_count = $this->db->query('SELECT COUNT(content_id) AS `content_count` FROM `content` WHERE `content_type_id`=\'' . $filters['type'] . '\'');
			$content_count = $content_count->row()->content_count;
		}
	
		// do we need to get all content data?  i.e., does it make resource saving sense?
		if (isset($filters['id']) or isset($filters['type'])) {
			// add a hit to the content
			if (isset($filters['id'])) {
				$this->add_hit($filters['id']);
			}
		
			// find out the table name
			$this->db->select('content_type_id');
			if (isset($filters['id'])) {
				$this->db->where('content_id',$filters['id']);
			}
			elseif (isset($filters['type'])) {
				$this->db->where('content_type_id',$filters['type']);
			}
			
			$result = $this->db->get('content');
			
			if ($result->num_rows() == 0) {
				return FALSE;
			}
			else {
				$row = $result->row_array();
				
				$this->load->model('publish/content_type_model');
				$type = $this->content_type_model->get_content_type($row['content_type_id']);
				
				// get custom fields
				$this->load->model('custom_fields_model');
				$custom_fields = $this->custom_fields_model->get_custom_fields(array('group' => $type['custom_field_group_id']));
				
				// if we are running a keyword search, we need to join the articles table now, unfortunately
				if (isset($filters['keyword'])) {
					// take care of the select for this specific content type, being careful not to duplicate content_id
					foreach ($custom_fields as $field) {
						$this->db->select($type['system_name'] . '.' . $field['name']);
					}
					reset($custom_fields);
					
					$this->db->join($type['system_name'], 'content.content_id = ' . $type['system_name'] . '.content_id','left');
					$content_table_join = FALSE;
				}
				else {
					// join this table into the mix, later
					$content_table_join = $type['system_name'];
				}
				
				// are we doing a fulltext search?
				// either a LIKE or FULLTEXT
				if (isset($filters['keyword']) and $content_count > 10) {
					$search_fields = array();
					foreach ($custom_fields as $field) {
						$search_fields[] = '`' . $field['name'] . '`';
					}
					reset($custom_fields);
					
					$search_fields = implode(', ', $search_fields);
					
					$this->db->where('(MATCH (' . $search_fields . ') AGAINST ("' . $filters['keyword'] . '") OR `content`.`content_title` LIKE \'%' . $filters['keyword'] . '%\')', NULL, FALSE);  
					
					$this->db->select('MATCH (' . $search_fields . ') AGAINST ("' . $filters['keyword'] . '") AS `relevance`', FALSE);
				}
				elseif (isset($filters['keyword']) and $content_count <= 10) {
					// we aren't doing a fulltext search, let's get rid of their relevance order
					if ($filters['sort'] == 'relevance') {
						unset($filters['sort']);
					}
					
					$search_fields = array();
					$method = 'like';
					foreach ($custom_fields as $field) {
						$this->db->$method($field['name'],$filters['keyword']);
						$method = 'or_like';
					}
					reset($custom_fields);
				}
			}
		}
		else {
			// don't join a content type table
			$content_table_join = FALSE;
		}
	
		if (isset($filters['start_date'])) {
			$start_date = date('Y-m-d H:i:s', strtotime($filters['start_date']));
			$this->db->where('content.content_date >=', $start_date);
		}
		
		if (isset($filters['end_date'])) {
			$end_date = date('Y-m-d H:i:s', strtotime($filters['end_date']));
			$this->db->where('content.content_date <=', $end_date);
		}
		
		if (isset($filters['type'])) {
			$this->db->where('content.content_type_id',$filters['type']);
		}
		
		if (isset($filters['id'])) {
			$this->db->where('content.content_id',$filters['id']);
		}
		
		if (isset($filters['is_standard'])) {
			$this->db->where('content.content_is_standard',$filters['is_standard']);
		}
		
		if (isset($filters['title'])) {
			$this->db->like('content.content_title',$filters['title']);
		}
		
		if (isset($filters['author'])) {
			if (!is_array($filters['author'])) {
				$this->db->where('content.user_id',$filters['author']);
			}
			else {
				$this->db->where_in('content.user_id',$filters['author']);
			}
		}
		
		if (isset($filters['topic'])) {
			if (!is_array($filters['topic'])) {
				$this->db->join('topic_maps','topic_maps.content_id = content.content_id');
				$this->db->where('topic_maps.topic_id',$filters['topic']);
			}
			else {
				$this->db->join('topic_maps','topic_maps.content_id = content.content_id');
				$this->db->where_in('topic_maps.topic_id',$filters['topic']);
			}
		}
		
		// will we allow future content?
		if (!isset($filters['allow_future']) or $filters['allow_future'] != TRUE) {
			$this->db->where('content.content_date <',date('Y-m-d H:i:s'));
		}
		
		// standard ordering and limiting
		$order_by = (isset($filters['sort'])) ? $filters['sort'] : 'content.content_id';
		$order_dir = (isset($filters['sort_dir'])) ? $filters['sort_dir'] : 'DESC';
		$this->db->order_by($order_by, $order_dir);
		
		if (isset($filters['limit'])) {
			$offset = (isset($filters['offset'])) ? $filters['offset'] : 0;
			$this->db->limit($filters['limit'], $offset);
		}
		
		// we want to select everything from the content database
		$this->db->select('content.*');
		// if we are dipping into the specific content db (e.g., `articles), we're taking
		// care of that select above
		
		$this->db->from('content');
		
		if ($counting == FALSE) {
			// get the query we've been building for the embedded select, then clear the active record
			// query being built
			$embedded_from_query = $this->db->_compile_select();
			$this->db->_reset_select();
		}
		else {
			return $this->db->get()->num_rows();
		}
		
		// this filter has to be applied late, because the users table needs to be joined
		if (isset($filters['author_like'])) {
			$this->db->like('users.user_username',$filters['author_like']);
		}
		
		$this->db->join('users','users.user_id = content.user_id','left');
		$this->db->join('content_types','content_types.content_type_id = content.content_type_id','left');
		$this->db->join('links','links.link_id = content.link_id','left');
		if ($content_table_join !== FALSE) {
			$this->db->join($content_table_join, 'content.content_id = ' . $content_table_join . '.content_id','left');
		}
		
		$this->db->select('* FROM (' . $embedded_from_query . ') AS `content`',FALSE);
		
		$result = $this->db->get();
		
		if ($result->num_rows() == 0) {
			return FALSE;
		}
		
		$date_format = (isset($filters['date_format'])) ? $filters['date_format'] : FALSE;
		
		$contents = array();
		foreach ($result->result_array() as $content) {
			$this_content = array(
								'id' => $content['content_id'],
								'link_id' => $content['link_id'],
								'date' => local_time($content['content_date'], $date_format),
								'modified_date' => local_time($content['content_modified'], $date_format),
								'author_id' => $content['user_id'],
								'author_username' => $content['user_username'],
								'author_first_name' => $content['user_first_name'],
								'author_last_name' => $content['user_last_name'],
								'author_email' => $content['user_email'],
								'type_id' => $content['content_type_id'],
								'type_name' => $content['content_type_friendly_name'],
								'is_standard' => ($content['content_is_standard'] == '1') ? TRUE : FALSE,
								'title' => ($content['content_is_standard'] == '1') ? $content['content_title'] : 'Entry #' . $content['content_id'],
								'url_path' => $content['link_url_path'],
								'url' => site_url($content['link_url_path']),
								'privileges' => (!empty($content['content_privileges'])) ? unserialize($content['content_privileges']) : FALSE,
								'topics' => (!empty($content['content_topics'])) ? unserialize($content['content_topics']) : FALSE,
								'template' => $content['content_type_template'],
								'hits' => $content['content_hits'],
								'relevance' => (isset($content['relevance'])) ? $content['relevance'] : FALSE
							);
							
			// are we loading in all content data?
			if (isset($custom_fields) and !empty($custom_fields)) {
				foreach ($custom_fields as $field) {
					$this_content[$field['name']] = $content[$field['name']];
				}
				reset($custom_fields);
			}
			
			$contents[] = $this_content;
		}
		
		return $contents;
	}
}	