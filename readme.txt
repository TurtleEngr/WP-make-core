=== makecore ===
Description: Make the current WordPress site ready to be a core site.
Contributors: turtle-engr
Tags: plugin, make-core, cleanup
Requires at least: 6.0
Tested up to: 7.1
Stable tag: VERSION
License: GPLv2
License URI: <http://www.gnu.org/licenses/gpl-2.0.html>

== Description ==

Make the current WordPress site ready to be a core site. ONLY DO
THIS ON A COPY OF YOU MAIN SITE. However you can install this on
your main site, so you can define the URLs for the pages and posts
to keep (then deactivate the plugin).

You define the pages and posts that need to save. The List button will
show you all the pages and posts that will be deleted. You can then
copy the ones you want to keep to the Save text boxes (then do List
again). If no pages are in the save text boxes, all will be listed.

When you press the Delete button:
- You will be prompted, with: Are you sure?
- The URLs in the List boxes will be deleted
- Revisions will be removed from remaining pages and posts
- Media files that are not referenced will be deleted

For complete documentation and the work flow for making a core site
see: https://github.com/TurtleEngr/WP-make-core/tree/main

== Installation ==

Source: https://moria.whyayh.com/rel/released/software/own/make-core

1. Download the makecore-VERSION.zip file.
2. Use Add Plugins, Upload Plugin, Install, Activate

== Changelog ==

### 0.2

- First version
