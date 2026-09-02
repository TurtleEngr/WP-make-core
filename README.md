# make-core

![version](https://img.shields.io/badge/version-0.4.5-orange.svg)

![WordPress](https://img.shields.io/badge/WordPress-Compatible-blue.svg)

Make the current WordPress site ready to be a core site.

## Installation

Source: <https://moria.whyayh.com/rel/released/software/own/make-core>

1.  Download the makecore-0.4.5.zip file.
2.  Use Add Plugins, Upload Plugin, Install, Activate

## How do I use this plugin?

Work flow:

1.  Install plugin to main site.

2.  Go to Settings -\> MakeCore. With blank Keep text boxes, click on
    the List button (only).

3.  Copy the URIs listed in the Delete Posts and Delete Pages text
    boxes, to an editor. Delete the ones the should be deleted.

4.  Copy the URIs from the editor tot the Keep text boxes. Click on the
    List button (only).

5.  Repeat steps 3 and 4 until the Delete lists look OK. DO NOT CLICK ON
    THE Delete button.

6.  Using your hosting provider\'s process, copy your main site to a
    \"test\" site. ALL THE OTHER STEPS ARE DONE ON THE TEST SITE COPY.

7.  Go to Settings -\> MakeCore. With blank Keep text boxes, click on
    the List button.

8.  Verify the lists in the Delete text boxes. Adjust the Keep text
    boxes as needed. If changed, repeat step 7 and 8.

9.  Click on the Delete button:

    -   You will be prompted, with: Are you sure?
    -   The URLs in the List boxes will be deleted
    -   Revisions will be removed from remaining pages and posts
    -   Media files that are not referenced will be deleted

## Manual Cleanup Steps

1.  If Imagely plugin is installed:
    -   Delete all Galleries
    -   Delete all Albums
    -   Delete all Tags
2.  Replace front page with \"Core Home\"
    -   Duplicate \"Core Home\" and edit it.
    -   Change title to \"Home\"
    -   Change slug to \"Home\"
    -   Save
    -   Settings -\> Reading, Set Homepage to \"Home\"
3.  Appearance -\> Customize
    -   Remove Logo
    -   Change Site Identity to \"Core Sample\"
4.  Edit *change-log*
    -   Remove all of the entries.
    -   Add Date for when this core was created.
5.  Remove all users, but leave one admin user.
    -   TBD: Can a dummy admin user be created, then when the core is
        installed, the user is prompted for an admin user
        email/password.

## Export the Core (Hostinger)

-   Log in to your Hostinger hPanel.
-   Go to Websites and click Manage next to your WordPress site.
-   On the left sidebar, navigate to Files -\> Backups.Under Files
    backups, click Select.
-   Choose the most recent date from the dropdown menu and click Next
    step.
-   Click on Website backup. This includes all files and DB.
