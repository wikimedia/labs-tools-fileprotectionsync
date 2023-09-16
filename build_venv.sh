#!/bin/bash

## (Re)builds python virtual environment on Toolforge.
set -e

rm -rfd pywikienv

python3 -m venv pywikienv

source pywikienv/bin/activate

pip install --upgrade pip setuptools wheel
pip install --upgrade -r src/fileprotectionsync/requirements.txt

