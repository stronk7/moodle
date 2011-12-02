#!/bin/bash
# $gitdir: Directory containing git repo
# $csdir: Directory containing moodle phpcs standard definition
# $gitbranch: Branch we are going to check
# $extraoptions: Extra options to pass to phpcs
# $extraignore: Extra ignore dirs

# file where results will be sent
resultfile=${WORKSPACE}/coding_standards_detector.xml

# calculate some variables
mydir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

# list of excluded dirs
. ${mydir}/../define_excluded/define_excluded.sh

# checkout pristine copy of the configure branch
cd ${gitdir} && git checkout ${gitbranch} && git fetch && git reset --hard origin/${gitbranch}

# Run phpcs against the whole codebase
/opt/local/bin/php ${mydir}/coding_standards_detector.php --report=checkstyle --report-file="${resultfile}" --standard="${csdir}" --ignore="${excluded_comma_wildchars}${extraignore}" ${extraoptions} $gitdir
# Always return ok
exit 0
