<?php
class stash_Core
{
	public function save($file)
	{
		// Check there is an uploaded file
		if (empty($file['tmp_name']) OR ! is_uploaded_file($file['tmp_name'])) return FALSE;
		
		$dir = stash::get_directory();
		$token = stash::create_token();
		
		// Stash file
		move_uploaded_file($file['tmp_name'], $dir.$token.'_file');
		
		// Stash meta data
		unset($file['tmp_name']);
		$meta = serialize($file);
		file_put_contents($dir.$token.'_meta', $meta);
		
		if(mt_rand(1, 100) <= Kohana::config('stash.gc_probability'))
		{
			stash::garbage_collect();
		}
		
		return $token;
	}
	
	
	public function load($token, $key = NULL)
	{
		if(stash::valid($token))
		{
			$dir = stash::get_directory();
			
			$meta = file_get_contents($dir.$token.'_meta');
			$meta = unserialize($meta);
			
			$meta['tmp_name'] = $dir.$token.'_file';
			
			return ($key === NULL) ? $meta : $meta[$key];
		}
		else
		{
			return FALSE;
		}
	}
	
	
	public function valid($token)
	{
		if( ! is_string($token) OR strlen($token) != 64 OR ! ctype_alnum($token))
		{
			return FALSE;
		}
		else
		{
			$dir = stash::get_directory();
			return (file_exists($dir.$token.'_file') AND file_exists($dir.$token.'_meta'));
		}
	}
	
	
	protected function get_directory()
	{
		static $dir;
		
		if( ! isset($dir))
		{
			$dir = Kohana::config('stash.directory', TRUE, TRUE);
			
			if ($dir === NULL)        throw new Kohana_User_Exception('Stash Directory Not Defined', '');
			if ( ! is_dir($dir))      throw new Kohana_User_Exception('Stash Directory Does Not Exist', '');
			if ( ! is_writable($dir)) throw new Kohana_User_Exception('Stash Directory Is Not Writable', '');
		}
		
		return $dir;
	}
	
	
	protected function create_token()
	{
		// Token will always be 64 chars, as uniqid is 13 chars
		$unique_part = uniqid();
		$secure_part = text::random('alnum', 51);
		return $unique_part.$secure_part;
	}
	
	
	protected function garbage_collect()
	{
		$lifetime = Kohana::config('stash.lifetime');
		$lifetime = ($lifetime > 0) ? $lifetime : 7200;
		
		$oldest = time() - $lifetime;
		
		foreach(glob(stash::get_directory().'*') as $file)
		{
			if (filectime($file) < $oldest)
			{
				unlink($file);
			}
		}
	}
	
}
