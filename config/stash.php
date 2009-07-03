<?php
// Directory to store stashed files
$config['directory'] = APPPATH.'../tmp';

// How long to keep files for (2 hours by default)
$config['lifetime'] = 7200;

// Percentage probability that garbage collection
// will  be run when stashing a file
$config['gc_probability'] = 25;
