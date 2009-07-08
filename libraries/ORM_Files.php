<?php
class ORM_Files extends ORM
{
	// Lists of files to be copied in or deleted when the object is saved
	protected $files_pending_attachment = array();
	protected $files_pending_removal    = array();


	public function attach_uploaded_file($field, $upload)
	{
		if( ! upload::valid($upload))
		{
			throw new Kohana_User_Exception('Not A Valid Upload', 'The supplied array did not specify a valid uploaded file');
		}

		$this->remove_file($field);

		$this->files_pending_attachment[$field] = array($upload, 'upload');
	}
	
	
	public function attach_stashed_file($field, $stash)
	{
		if( ! is_array($stash))
		{
			$stash = stash::load($stash);
		}

		$this->remove_file($field);

		$this->files_pending_attachment[$field] = array($stash, 'stash');
	}


	public function attach_local_file($field, $file, $move = FALSE)
	{
		if( ! file_exists($file) OR is_dir($file))
		{
			throw new Kohana_User_Exception('File Does Not Exist', 'The file <tt><'.$file.'/tt> does not exist so cannot be attached');
		}

		if($move)
		{
			if ( ! is_writable(dirname($file)))
				throw new Kohana_User_Exception('File Not Movable', 'The file <tt><'.$file.'/tt> cannot be moved because its directory is not writable by Kohana');
		}
		else
		{
			if ( ! is_readable($file))
				throw new Kohana_User_Exception('File Not Copyable', 'The file <tt><'.$file.'/tt> cannot be copied because it is not readable by Kohana');
		}

		$this->remove_file($field);

		$this->files_pending_attachment[$field] = array($file, $move ? 'move' : 'copy');
	}


	public function remove_file($field)
	{
		unset($this->files_pending_attachment[$field]);

		if ($path = $this->file_path($field))
		{
			$this->files_pending_removal[$field] = $path;
		}
	}


	public function has_file($field)
	{
		if (isset($this->files_pending_attachment[$field])) return TRUE;
		if (isset($this->files_pending_removal[$field]))    return FALSE;
		return $this->object[$field] != '';
	}


	// Given a field name, returns the path to the associated file
	public function file_path($field)
	{
		if (isset($this->files_pending_attachment[$field]))
		{
			list($file, $type) = $this->files_pending_attachment[$field];
			return ($type == 'upload' OR $type == 'stash') ? $file['tmp_name'] : $file;
		}
		elseif (isset($this->files_pending_removal[$field]) OR $this->object[$field] == '')
		{
			return FALSE;
		}
		else
		{
			return $this->associated_files_path().$this->object[$this->primary_key].'-'.$field.'-'.$this->object[$field];
		}
	}


	public function save()
	{
		foreach($this->files_pending_removal as $field => $filename)
		{
			if(file_exists($filename))
			{
				unlink($filename);
			}
			$this->$field = '';
		}

		$this->files_pending_removal = array();


		if (count($this->files_pending_attachment))
		{
			// If it doesn't already have an ID we need to save it first to get one
			if ( ! $this->loaded)
			{
				if (empty($this->changed))
				{
					// We need to set at least one field to ensure object actually gets saved
					$field = key($this->files_pending_attachment);
					$this->$field = '';
				}
				parent::save();
			}

			foreach($this->files_pending_attachment as $field => $details)
			{
				list($file, $type) = $details;
				$this->attach_associated_file($field, $file, $type);
			}

			$this->files_pending_attachment = array();
		}

		return parent::save();
	}


	public function delete($id = NULL)
	{
		if ($id === NULL AND $this->loaded)
		{
			// Use the the primary key value
			$id = $this->object[$this->primary_key];
		}

		parent::delete($id);

		foreach(glob($this->associated_files_path().$id.'-*') as $file)
		{
			unlink($file);
		}

		return $this;
	}


	public function delete_all($ids = NULL)
	{
		parent::delete_all($ids);

		if (is_array($ids))
		{
			foreach($ids as $id)
			{
				foreach(glob($this->associated_files_path().$id.'-*') as $file)
				{
					unlink($file);
				}
			}
		}
		elseif (is_null($ids))
		{
			foreach(glob($this->associated_files_path().'*') as $file)
			{
				unlink($file);
			}
		}

		return $this;
	}


	public function load_values(array $values)
	{
		// Clear any files pending attachment or removal
		$this->files_pending_attachment = array();
		$this->files_pending_removal = array();

		return parent::load_values($values);
	}


	protected function attach_associated_file($field, $file, $type)
	{
		switch($type)
		{
			case 'upload':
			case 'stash':
				$path = $file['tmp_name'];
				$name = $file['name'];
				break;

			case 'copy':
			case 'move':
				$path = $file;
				$name = $file;
				break;

			default:
				throw new Kohana_User_Exception('!!!', '');
		}

		// Get original filename and extension
		$pathinfo = pathinfo($name);

		// Limit filename and sanatise
		$filename = text::limit_chars($pathinfo['filename'], 15, '');
		$filename = url::title($filename, '_');

		// Sanatise extension
		$extension = trim(preg_replace('#[^a-z0-9]#', '', strtolower($pathinfo['extension'])));

		$directory = $this->associated_files_path();

		// Check that the directory exists, create it if not
		if ( ! is_dir($directory))
		{
			mkdir($directory, 0777, TRUE);
		}

		// Check it's writable
		if ( ! is_writable($directory))
			throw new Kohana_Exception('upload.not_writable', $directory);

		$prefix = $directory.$this->object[$this->primary_key].'-'.$field.'-';

		$name = time().'-'.$filename.($extension ? ".$extension" : '');

		switch($type)
		{
			case 'upload':
				move_uploaded_file($path, $prefix.$name);
				break;

			case 'move':
			case 'stash':
				rename($path, $prefix.$name);
				break;

			case 'copy':
				copy($path, $prefix.$name);
				break;
		}

		$this->$field = $name;

	}


	public function associated_files_path()
	{
		static $cache;

		if ( ! isset($cache[$this->object_name]))
		{
			$instance_name = Database::instance_name($this->db);
			$directory = Kohana::config('orm_files.'.$instance_name, TRUE, TRUE);

			$cache[$this->object_name] = $directory.$this->db->table_prefix().$this->table_name.'/';
		}

		return $cache[$this->object_name];
	}

}
