Program: SageTVRename
Developed by: Bryan Price
Email: southernprogrammer@gmail.com

About the Script:

Purpose:
This script should be used for creating symlinks for SageTV files in such a way that XBMC or Boxee can detect the media.


Requirements: 

OS: Windows Vista, Windows 7, OS X, or Linux.

DVR Software: SageTV, SageTV Web Interface (http://www.geektonic.com/2008/03/sagetv-web-interface-control-sagetv.html)

Other: Php Must be installed and you should make sure that the location of php is located in the "Path"
Environmental Variable in Windows (a quick google search should help)

This script also must run on the same machine that SageTv is running on.

Installation:

Copy the SageTVRename folder anywhere you like, create a shortcut of launch.bat and copy the shortcut into your startup folder (start->all programs->startup)
Doing this starts the rename program at startup of your computer to run at intervals of 30 mins (modify run.bat to change interval).

You will also need to create/edit a configuration file.


Editing the configuration file:

1. Edit config.xml with <dir> as the directory that will be monitored (SageTV Folder), multiple <dir> tags are allowed, so multiple SageTV folders 
are allowed to be monitored. Delete or add <dir> tags as needed under the <dirs> (plural) tag as needed.

2. The <newdir> to where the symlink files will be moved created.

2. If you have setup authentication on the web interface of SageTV, please set Authentication as True and set username and password accordingly.  Else put
false and leave username and password as blank.  

3  Add Multiple <ignore> tags to the configuration.xml file to ignore certain shows, if needed.  This script
recognizes the show's name. For instance I’m ignoring the show “College Football”, because I know for a fact that my script will name it completely wrong if I don’t.

4. If you want a correction done to the way a filename is named add a <customname> tag with a <from> tag with the way the rename script is naming it and a
<to> tag to the way you want it named. For example if I want "The Office.SXXEXX (Episode Title)" to be renamed  "The Office (US).SXXEXX (Episode Title)" 
I would have a customname tag as such:

<customname>
	<from>The Office</from>
	<to>The Office (US)</to>
</customname>

5. If you want year names in your folder names please set the follow tag with a value of True <episodeYearInFolder>

Usage:

If you want to start the process without having to restart, simply run launch.bat
A typical file name looks as follows: "Maverick (1957)\Maverick.S04E32 (The Devil's Necklace).mpg". If the script
detects that your file is a movie, it will appear in the form "Movies\Movie Name.mpg" If the script doesn't detect
what your file is, it does not create a symlink.

Note:
Previously scanned files that were successful are stored in an xml file called processed.xml.  
If you want something to be rescanned, delete it's record in the xml file.


Special Thanks:

Thank you TVRage.com for your wonderful feed feature for gathering TV information. Thank you Keith Devens for your
wonderful xml.php file which made this whole process a lot easier. And thank you neilm for your wonderful web interface, for which this would
not be possible.

Enjoy :-D