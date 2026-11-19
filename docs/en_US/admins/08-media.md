# 8 Media: uploads, converters and thumbnails

camindo CMS accepts files via ***upload fields*** and automatically creates from them the
versions that the website needs: reduced images in several sizes, preview images, web-compatible
videos, still images from videos, compressed audio files. Three terms are important here:

| Term | Meaning |
|---|---|
| ***Processor*** | a program module that can convert files – for example “GD library” or “FFmpeg (video)” |
| ***Converter*** | a configured processor: *which* files it converts *how* – for example “Image 800 px wide, JPEG, quality 80” |
| ***Thumbnail field*** | a field that generates a version from the file of another field with one or more converters |

## Processors

The following processors are delivered with the core:

| Processor | Task | Requirement |
|---|---|---|
| GD library | scale, crop, convert images – basic functions without additional software | PHP extension `gd` |
| ImageMagick | images in very many formats (also HEIC, TIFF, PSD), expert mode with your own parameters, also in the background | ImageMagick on the server |
| FFmpeg (video) | convert, scale, compress videos | ffmpeg |
| FFmpeg (audio) | convert audio files, align loudness, excerpts | ffmpeg |
| FFmpeg (preview image) | still image from a video | ffmpeg |

Further processors can be installed later as [components](./10-modules.md#components).
Whether ImageMagick and ffmpeg are found is shown by the module “Tools” on the tab “Server”.

## Setting up converters

You create converters in the module “Media converters”; the module is reserved for superusers. The installation already sets up
the converter ***“Internal Image Preview (GD)”***: it converts every image format the
GD library reads into JPEG images of at most 200 px width and 300 px height.


| Setting | Meaning |
|---|---|
| Processor | which processor does the work |
| Label | name of the converter |
| Included media types | which files the converter applies to: MIME type (`image/jpeg`), group (`image/*`) or extension (`.pdf`), one entry per line |
| Excluded media types | exceptions, for example `image/gif`, so that animated GIFs remain unchanged |
| File extension | format of the generated file, e.g. `.jpg` or `.mp4`; empty = keep the format |
| Conversion options | depending on the processor: width and height, fit or crop, only reduce or only enlarge the size, quality, background color, codec, data rate, excerpt, remove metadata, conversion in the background … |

***Own parameters (expert mode):*** With ImageMagick and FFmpeg you can add parameters of
your own. Paths, URLs and options that write or read further files (such as `-write` or `-i`)
are blocked; camindo CMS then does not save the converter and names the rejected parameter.

***Size with ImageMagick and the GD library:*** In addition to “Target width” and
“Target height”, four settings determine how the image is brought to this size:

| Setting | Choice | Effect |
|---|---|---|
| Fit to format | Fit the whole image into the format | the whole image fits inside, one side may be shorter |
| | Fill the format completely and crop | fills the format; overhang is cropped at the center |
| | Fill the format completely, let it overhang (^) | fills the format; one side may be longer (nothing is cropped) |
| | Force the exact format, ignore the aspect ratio (!) | width and height exact – the image is distorted in the process |
| Resize | Always / Shrink only / Enlarge only | “Shrink only”: smaller images keep their size – a thumbnail 440 px wide is not scaled up from a 300 px wide image. “Enlarge only”: larger images keep their size |
| Unit | Pixels / Percent of the original (%) | with percent, target width and height are percentage values, e.g. `50` for half the size (only with fit or distort) |
| Maximum number of pixels | number | scales proportionally until width × height equals this value, e.g. `1000000` for one megapixel; applies after target width and height and likewise follows “Resize” – with “Shrink only”, only larger images are reduced, with “Always”, smaller ones are enlarged as well |

Fill, crop, and distort require both dimensions; if one is missing, fit applies.
Cropping with “Shrink only” crops smaller images only to the target format at the center, as far
as the image extends – there is no border in the background color.

***Differences with the GD library:*** Instead of “Fit to format”, there is the
setting “Crop” with only two choices: “Fit entirely into new dimensions” and “Fill new dimensions
completely” (crops at the center). “Resize”, “Unit” and “Maximum number of pixels” work as with
ImageMagick; percent applies only without cropping. One percentage value applies to both
sides; two different percentage values scale width and height separately.

Further settings:

| Setting | Choice | Effect |
|---|---|---|
| Quality | percent | quality of compressed formats such as JPEG; 100 % = best quality, but the largest file |
| Number of colors | number or `TrueColor` | The formats GIF and PNG allow a reduced color palette (at most 256 colors). The number can be entered here, e.g. `2`, `8` or `128`. `TrueColor`: do not reduce the color palette |
| Transparency | keep alpha channel | switched on: transparent areas remain transparent; switched off: they are filled with the background color |
| Background color | hexadecimal value, e.g. `#ffffff` | replaces the transparency if the alpha channel is not kept |

The detail view of a converter shows on the tab “Used by …” which thumbnail fields use it. If a
converter in use is deleted, these fields no longer generate versions – the confirmation warns
about this.

> ***PDF and SVG with ImageMagick:*** camindo CMS allows ImageMagick to work only with its own
> security policy. SVG and PDF are blocked in it until you release them in `.camindo.php`:
>
> ```php
> 'security' => [
>     'magick_allow' => ['svg'],          // or ['svg', 'pdf']
> ],
> ```
>
> ImageMagick reads PDF via Ghostscript, which has repeatedly been a gateway for attacks via
> manipulated files – release it only if you need it. Whether a format really works is shown in the
> detail view of the converter under “Security policy”: the policy of the server can also block it.
> PDF is blocked by many providers; camindo CMS cannot change this. More on this:
> [Operation – securing ImageMagick](./12-operation.md#securing-imagemagick).

## Thumbnail fields

A thumbnail field (field type “File version (thumbnail)”) has a ***source*** – an upload field or
another thumbnail field – and a sorted list of ***converters***. For each new file, camindo CMS
goes through the converters in turn; the first one whose media types match creates the version.
So a field “preview” can use an image converter for images and a still-image converter for videos.

Thumbnail fields can be ***chained***. Example for a video:

```
video                  upload field
 ├── video_web         FFmpeg (video): MP4/H.264, 1920 px, in the background
 └── video_standbild   FFmpeg (preview image): still image at 10 % of the runtime
      └── video_teaser ImageMagick: 400 × 225 px cropped, JPEG
```

The versions are created when the record is saved, once – not with every interim save.
Images are converted immediately; videos and other complex conversions run as a background process
(for this, the [cron job](./12-operation.md#cron-jobs) must be running). Until then, the back end
shows a placeholder image.

> The placeholder image remains in the view even after the background process has finished – the
> view is only refreshed by clicking the button “Refresh”.
>
> The processor “GD library” does not work in the background. If a version is to be created from a
> file that is itself only generated in the background (for example from the still image of a video),
> use ImageMagick.

### Regenerating thumbnails

If you change the settings of a converter or add a new thumbnail field to an area, this initially
applies only to new uploads. For the existing content, “Tools” → “Data” → “Regenerate thumbnails”
recreates the versions of all records of an area – in the background for many records.

## Upload fields

| Setting | Meaning |
|---|---|
| Allowed file types | MIME types or extensions, one entry per line; empty = all permitted |
| Multilingual | one file per language, for example a brochure in each language |
| Signed-in frontend users only | deliver the file only to signed-in frontend users (see [chapter 13](./13-community.md#protected-files)) |
| Embed invisible watermark | images get the watermark code of the instance (see [below](#invisible-watermark)); also available for thumbnail fields |

For security reasons, executable files (`.php`, `.phtml`, `.cgi`, `.sh`, `.htaccess` and others)
are always forbidden, and camindo CMS checks whether the content of a file matches its extension –
another format disguised as `.jpg` is rejected.

### Large files: the upload directory

The size of an upload via the browser is limited by the PHP configuration of the server
(`upload_max_filesize`, `post_max_size`). Larger files – such as videos – are transferred to the
directory `var/upload/` via FTP or SCP. In the input mask and during import, editors select them via
![folder_open](../_/folder_open.svg) “Select file on the server”, the button to the right of each
upload field.

## Invisible watermark

Images can carry an ***invisible watermark***: a code of one to six characters that is part
of the image itself – not of the metadata, which usually gets lost when an image is processed
further. The watermark survives scaling down and up, JPEG compression, turning by 90° and
mirroring. So you can check later which website an image comes from.

How to set it up:

1. The system administrator installs the program `camindo-watermark` on the server, enters its
   path as `paths.watermark` in the configuration and creates the secret key with
   `php Core/cron/camindo.php watermark key` (see [chapter 12](./12-operation.md)).
2. In the module “Instances”, every instance gets its own ***code*** under ***Watermark***,
   e.g. `GS-NRD` – one to six characters from the letters A–Z and the hyphen. Lower case is
   converted to upper case.
3. In the upload and thumbnail fields whose images should carry the watermark, switch on
   ***Embed invisible watermark***.

From then on, every newly saved image of these fields gets the code of its instance – on upload,
in every version created from it and after image editing on the info page. For existing
thumbnails, “Regenerate thumbnails” is enough; originals that were uploaded before only get the
watermark with the next upload.

Good to know:

- Formats: JPEG and PNG, WebP only with ImageMagick. GIF, SVG and other formats stay unchanged.
- ***Cropping*** destroys the watermark. Therefore switch it on in the thumbnail field if the
  thumbnail shows a different section (for example a square preview image from a wide format) –
  then it is embedded again after cropping.
- Below about 300 px width or 200 px on the short side, the code can no longer be read reliably.
  The watermark is not worth it for very small preview images.
- It cannot be seen on photos. On graphics and screenshots with large areas of one color, slight
  smudges can appear at text and edges.
- If the program is configured but not usable (program or key missing), an image in a field with a
  watermark cannot be saved. The instance form points this out.

You can check an image on the command line with
`php Core/cron/camindo.php watermark detect image.jpg` – the output names the code and the
instance – or with the checking website that comes with the program.

The program, the checking website and the installation instructions are available at
[github.com/dirkintelligence/camindo-watermark](https://github.com/dirkintelligence/camindo-watermark)
(downloads under “Releases”).

## Storage and delivery

Files are stored under `public/media/` in the structure
`{instance}/{field}/{record}.{extension}`. The addresses that the templates generate also carry the
original file name, for example `/media/1/12/1977,beach-north.jpg` – good for search engines and for
visitors who save a file.

- ***Domain lock:*** The files of an instance are delivered only via its domains (and the domains of
  the instances that may read them). This prevents files from being linked or displayed unintentionally
  via a foreign website. It is not an access control (see note below).
- ***Trash:*** If a record is deleted, its files go with it into the trash and return when it is restored.
- ***Protected fields:*** Files from fields with “Signed-in frontend users only” are blocked in the
  media directory and are delivered only after a check.

> ***Important: the domain lock is not access protection.***
> Which domain a request addresses is decided by the caller: they can set the host name freely (header
> `Host`). Anyone who knows the address of a file can therefore retrieve it even if they pose as another,
> authorized domain of the same installation. As a result, files of another instance can also be read.
> Complete protection can only be achieved with separate camindo installations;
> cf. the section [No multi-tenancy](./03-instances-and-languages.md#no-multi-tenancy).
>
> ***Confidential files*** are protected exclusively by the field option “Only for signed-in frontend
> users”. The directory of the field is then completely blocked on the server (`Require all denied`).
> Delivery takes place only via `file.php`, and only after a valid session of a frontend user has been
> checked (see [chapter 13](./13-community.md#protected-files)).
