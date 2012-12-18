# shoogle list

## what it does

ShoogleList is an MediaWiki-Extension to provide an overview of a specific category.
It's not just a simple list of articles inside a specific category - it parses data from a template used inside the article

An example can be found here: https://hackerspace-bamberg.de/Projekte

## Usage

To use shooglelist inside your mediawiki you only have to specify the shoogle-tag:

    <shoogle defaultdesc="This is a default description" defaultimg="Cat default processor.png">Projekt</shoogle>

As you can see, you are able to define an default image and description, which is used if neither the image or the description is specified in the article project-template. This examples indexes all articles inside the category "Projekt".

Possible arguments are:

    defaultdesc (string) - default description if the article doesnt have an description
    defaultimage (string) - default image if article doesnt have an image
    thumb_size (integer) - thumbnail size (default 180)
    trim_text (integer) - trims text to a given length (default off)
    limit (integer) - on "project of the day"-mode the amount of items shown


## Article Definition

In order to work correctly you have to specify a ProjektInfobox-Template inside your article:

    {{Infobox Projekt
    |name = ShoogleList Github Example
    |autor = schinken
    |beschreibung = Some basic usage example of shooglelist
    |image = ShoogleList Preview Image.png
    }}


This template is also available here: https://hackerspace-bamberg.de/index.php?title=Vorlage:Infobox_Projekt&action=edit and requires a lot of installation
and configuration to work (which i wont explain here :))

## Installation

Switch to your mediawiki-extension directory:

    cd /var/www/mediawik/extensions

Clone ShoogleList from my repostory (or a fork on your repository):

    git clone https://github.com/Schinken/shooglelist.git

instead you can also install it from tarball:

    wget 'https://github.com/Schinken/shooglelist/tarball/master' -O ShoogleList.tar.gz
    tar xvfz ShoogleList.tar.gz

... and as the last step, you need to add the extension to your LocalSettings.php (in your mediawiki-root directory):

    include_once("$IP/extensions/ShoogleList/ShoogleList.php");
