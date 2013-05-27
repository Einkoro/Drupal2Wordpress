<?php
	
	require_once("php-mysql.php");

	//Database Host Name
	$DB_HOSTNAME	= 'localhost';
	
	//Wordpress Database Name, Username and Password
	$DB_WP_USERNAME	= 'user';
	$DB_WP_PASSWORD	= 'pass';
	$DB_WORDPRESS	= 'database';

	//Drupal Database Name, Username and Password
	$DB_DP_USERNAME	= 'user';
	$DB_DP_PASSWORD	= 'pass';
	$DB_DRUPAL		= 'database';

	//Table Prefix
	$DB_WORDPRESS_PREFIX = 'wp_';
	$DB_DRUPAL_PREFIX	 = '';

	//Create Connection Array for Drupal and Wordpress
	$drupal_connection		= array("host" => "localhost","username" => $DB_DP_USERNAME,"password" => $DB_DP_PASSWORD,"database" => $DB_DRUPAL);
	$wordpress_connection	= array("host" => "localhost","username" => $DB_WP_USERNAME,"password" => $DB_WP_PASSWORD,"database" => $DB_WORDPRESS);

	//Create Connection for Drupal and Wordpress
	$dc = new DB($drupal_connection);
	$wc = new DB($wordpress_connection);

	//Check if database connection is fine
	$dcheck = $dc->check();	
	if (!$dcheck){
		echo "This $DB_DRUPAL service is AVAILABLE"; die();
	}

	$wcheck = $wc->check();	
	if (!$wcheck){
		echo "This $DB_WORDPRESS service is AVAILABLE"; die();
	}

	message('Database Connection successful');

	//Empty the current worpdress Tables	
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."comments");
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."links");
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."postmeta");
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."posts");
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."term_relationships");
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."term_taxonomy");
	$wc->query("TRUNCATE TABLE ".$DB_WORDPRESS_PREFIX."terms");
	message('Wordpress Table Truncated');
	
	//Get all drupal Tags and add it into worpdress terms table
	$drupal_tags = $dc->results("SELECT DISTINCT d.tid, d.name, REPLACE(LOWER(d.name), ' ', '-') AS slug FROM ".$DB_DRUPAL_PREFIX."term_data d INNER JOIN ".$DB_DRUPAL_PREFIX."term_hierarchy h ON (d.tid = h.tid) ORDER BY d.tid ASC");
	foreach($drupal_tags as $dt)
	{
		$wc->query("REPLACE INTO ".$DB_WORDPRESS_PREFIX."terms (term_id, name, slug) VALUES ('%s','%s','%s')", $dt['tid'], $dt['name'], $dt['slug']);
	}

	//Update worpdress term_taxonomy table
	$drupal_taxonomy = $dc->results("SELECT DISTINCT d.tid AS term_id, 'post_tag' AS post_tag, d.description AS description, h.parent AS parent FROM ".$DB_DRUPAL_PREFIX."term_data d INNER JOIN ".$DB_DRUPAL_PREFIX."term_hierarchy h ON (d.tid = h.tid) ORDER BY 'term_id' ASC");
	foreach($drupal_taxonomy as $dt)
	{
		$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."term_taxonomy (term_id, taxonomy, description, parent) VALUES ('%s','%s','%s','%s')", $dt['term_id'], $dt['post_tag'], $dt['description'], $dt['parent']);
	}

	message('Tags Updated');

	//Get all post from Drupal and add it into wordpress posts table
	$drupal_posts = $dc->results("SELECT DISTINCT n.nid AS id, n.uid AS post_author, FROM_UNIXTIME(n.created) AS post_date, r.body AS post_content, n.title AS post_title, r.teaser AS post_excerpt, n.type AS post_type,  IF(n.status = 1, 'publish', 'private') AS post_status FROM ".$DB_DRUPAL_PREFIX."node n, ".$DB_DRUPAL_PREFIX."node_revisions r WHERE (n.vid = r.vid)");
	foreach($drupal_posts as $dp)
	{
		$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."posts (id, post_author, post_date, post_content, post_title, post_excerpt, post_type, post_status) VALUES ('%s','%s','%s','%s','%s','%s','%s','%s')", $dp['id'], $dp['post_author'], $dp['post_date'], $dp['post_content'], $dp['post_title'], $dp['post_excerpt'], $dp['post_type'], $dp['post_status']);
	}
	message('Posts Updated');

	//Add relationship for post and tags
	$drupal_post_tags = $dc->results("SELECT DISTINCT node.nid, term_data.tid FROM (".$DB_DRUPAL_PREFIX."term_node term_node INNER JOIN ".$DB_DRUPAL_PREFIX."term_data term_data ON (term_node.tid = term_data.tid)) INNER JOIN ".$DB_DRUPAL_PREFIX."node node ON (node.nid = term_node.nid)"); 
	foreach($drupal_post_tags as $dpt)
	{
		$wordpress_term_tax = $wc->row("SELECT DISTINCT term_taxonomy.term_taxonomy_id FROM ".$DB_WORDPRESS_PREFIX."term_taxonomy term_taxonomy  WHERE (term_taxonomy.term_id = ".$dpt['tid'].")"); 
		$wc->query("INSERT INTO ".$DB_WORDPRESS_PREFIX."term_relationships (object_id, term_taxonomy_id) VALUES ('%s','%s')", $dpt['nid'], $wordpress_term_tax['term_taxonomy_id']);
	}
	message('Tags & Posts Relationships Updated');

	//Update the post type for worpdress
	$wc->query("UPDATE ".$DB_WORDPRESS_PREFIX."posts SET post_type = 'post' WHERE post_type IN ('blog')");
	message('Posted Type Updated');

	//Count the total tags
	$wc->query("UPDATE ".$DB_WORDPRESS_PREFIX."term_taxonomy tt SET count = ( SELECT COUNT(tr.object_id) FROM ".$DB_WORDPRESS_PREFIX."term_relationships tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id )");	
	message('Tags Count Updated');

	//Get the url alias from drupal and use it for the Post Slug
	$drupal_url = $dc->results("SELECT url_alias.src, url_alias.dst FROM ".$DB_DRUPAL_PREFIX."url_alias url_alias WHERE (url_alias.src LIKE 'node%')");
	foreach($drupal_url as $du)
	{
		$update = $wc->query("UPDATE ".$DB_WORDPRESS_PREFIX."posts SET post_name = '%s' WHERE ID = '%s'",$du['dst'],str_replace('node/','',$du['src']));
	}
	message('URL Alias to Slug Updated');

	//Move the comments and their replies - 1 Level
	$drupal_comments = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = 0)");
	foreach($drupal_comments as $duc)
	{
		$insert = $wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$duc['comment_ID'],$duc['comment_post_ID'],$duc['comment_author'],$duc['comment_author_email'],$duc['comment_author_url'],$duc['comment_author_IP'],$duc['comment_date'],$duc['comment_date'],$duc['comment_content'],'1','0');

		$drupal_comments_level1 = $dc->results("SELECT DISTINCT c.cid AS comment_ID, c.nid AS comment_post_ID, c.name AS comment_author, c.mail AS comment_author_email, c.homepage AS comment_author_url, c.hostname AS comment_author_IP, FROM_UNIXTIME(c.created) AS comment_date, field_data_comment_body.comment_body_value AS comment_content FROM ".$DB_DRUPAL_PREFIX."comment c INNER JOIN ".$DB_DRUPAL_PREFIX."field_data_comment_body field_data_comment_body ON (c.cid = field_data_comment_body.entity_id) WHERE (c.pid = ".$duc['comment_ID'].")");

		foreach($drupal_comments_level1 as $dcl1)
		{
			$wc->query("INSERT INTO  ".$DB_WORDPRESS_PREFIX."comments (comment_ID,comment_post_ID ,comment_author ,comment_author_email ,comment_author_url ,comment_author_IP ,comment_date ,comment_date_gmt, comment_content ,comment_approved,comment_parent)VALUES ('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')",$dcl1['comment_ID'],$dcl1['comment_post_ID'],$dcl1['comment_author'],$dcl1['comment_author_email'],$dcl1['comment_author_url'],$dcl1['comment_author_IP'],$dcl1['comment_date'],$dcl1['comment_date'],$dcl1['comment_content'],'1',$duc['comment_ID']);
		}
	}
	message('Comments Updated - 1 Level');

	//Update Comment Counts in Wordpress
	$wc->query("UPDATE ".$DB_WORDPRESS_PREFIX."posts SET comment_count = ( SELECT COUNT(comment_post_id) FROM ".$DB_WORDPRESS_PREFIX."comments WHERE ".$DB_WORDPRESS_PREFIX."posts.id = ".$DB_WORDPRESS_PREFIX."comments.comment_post_id )");

	message('Cheers !!');

	/*
		TO DO - Skipped coz didnt have much comment and Users, if you need then share you database and shall work upon and fix it for you.
		
		1.) Update Users/Authors
	*/
	
	//Preformat the Object for Debuggin Purpose
	function po($obj){
		echo "<pre>";
		print_r($obj);
		echo "</pre>";
	}

	function message($msg){
		echo "<hr>$msg</hr>";
		func_flush();
	}

	function func_flush($s = NULL)
	{
		if (!is_null($s))
			echo $s;

		if (preg_match("/Apache(.*)Win/S", getenv('SERVER_SOFTWARE')))
			echo str_repeat(" ", 2500);
		elseif (preg_match("/(.*)MSIE(.*)\)$/S", getenv('HTTP_USER_AGENT')))
			echo str_repeat(" ", 256);

		if (function_exists('ob_flush'))
		{
			// for PHP >= 4.2.0
			@ob_flush();
		}
		else
		{
			// for PHP < 4.2.0
			if (ob_get_length() !== FALSE)
				ob_end_flush();
		}
		flush();
	}
?>
