# PHP Microdata Parser and RDF extractor

# Overview

ARC2_MicrodataParser.php is an ARC2 module to parse microdata and extract RDF, according to "Microdata to RDF" W3C Note [1].

ARC2_MicrodataParser.php internally calls Microdata.php in order to parse microdata. Microdata.php is based on MicrodataPHP.php by Lin Clark, and can be used as an independent microdata parser (without ARC2_MicrodataParser.php). Requires PHP 5.2.

This tool was [originally created in 2012](http://www.kanzaki.com/works/2012/pub/1007-microdata2rdf.html).

## Installation

Place ARC2_MicrodataParser.php and Microdata.php in ARC2's 'parsers' directory.

## Credits

ARC2_MicrodataParser.php and Microdata.php are created and maintained by KANZAKI, Masahide, 2012. Licenses are the same as original:

- ARC2_MicrodataParser.php is licensed under The W3C Software License or the GPL.
- Microdata.php is licensed under MIT license.

If redistribute ARC2_MicrodataParser.php, Microdata.php must be bundled.

[1] This is based on 2012-09-19 version of Editor's draft http://dvcs.w3.org/hg/htmldata/raw-file/default/ED/microdata-rdf/20120919/index.html



## ARC2_MicrodataParser.php

This file follows the general style of ARC2 parser, so you can call this parser from within ARC2 application and use the extracted results (RDF triples) with standard ARC2 methods.

A simple usage might be:

```php
include_once($arc_path."ARC2.php");
$parser = ARC2::getParser("Microdata");
$parser->parse($uri);
$parser->toTurtle($parser->getTriples());
```

You can provide HTML data as 2nd param of `parse()`, as usual for ARC2 parsers.

### Optional features

`parser()` method havs optional 3rd parameter, which can set how much non-Microdata origin triples (defiend by "Microdata to RDF") to be added. Option value can be assigned using predefined constant:

| Const name | Value | means |
| --------------- |:--:| ---------------------------|
| MDEX_NONE | 0 | no extra triple |
| MDEX_PROPURI | 1 | propertyURI in registry, §3.1 |
| MDEX_MULTIVAL | 2 | multipleValues in registry, §3.2 |
| MDEX_VEXPANSION | 4 | vocab expansion: subPropertyOf, equivalentProperty, §4 |
| MDEX_DATATYPE | 8 | xsd:date etc, §5.1 |
| MDEX_TOPITEMS | 16 | md:items, §5.6 |
| MDEX_RDFAVOCAB | 32 | rdfa:usesVocabulary, §5.3 - step 7 |
| MDEX_VENTAIL | 64 | vocab entailment (partial support), §4.1 |
| MDEX_ALL | 127 | logical OR of the above |

Default setting is MDEX_PROPURI. Multiple options can be set by logical OR, e.g. `$parser->parse($uri, '', MDEX_PROPURI  |  MDEX_MULTIVAL)` will use both propertyURI and multipleValues setting in the registry. A simple registry (taken from http://www.w3.org/ns/md as of 2012-10-06) is built in the Class, though you can use different registry, by calling `$parser->setVocabRegistry("someregistry.json")` before `parse()`.

See the source comment for more detail.

### Caveat

- vocabulary entailment is patially implemented. See comment in `proc_extra_triples()`
- lang tag is generated only in case the itemprop element has lang attribute, i.e. inherent language information is not considered.

See https://github.com/semsol/arc2/ for more about ARC2.



## Microdata.php


The basic usage of Microdata.php is the same as that of MicrodataPHP.php:

```php
include_once("Microdata.php"); //if use independently
$md = new Microdata($url); //note the class name is the same as original
$json = $md->json(); //$md->obj() if need PHP object
```

### Extensions

Microdata.php extends MicrodataPHP.php in several ways.

- added `jsonld()` method to get JSON-LD serialization.
- constructor can have optional 2nd parameter, through which you can provide HTML data directly. If 2nd param presents, 1st param is treated as base URI.
- URI's are resolved against base URI

In order to generate RDF triples:

- changed property value from simple string to array of information (value type, lang etc.). Methods `obj()`, `json()` and `jsonld()` have optional boolean parameter, by setting it TRUE you can get this structured propetry value (default is false, so that simple call returns usual format).
- added some methods e.g. relative URI resolution.
- changed 'memory' checking algorythm to generate correct item structure.

### Original MicrodataPHP.php

MicrodataPHP is created by Lin Clark, Based on MicrodataJS. Source file is available at http://github.com/linclark/MicrodataPHP .
