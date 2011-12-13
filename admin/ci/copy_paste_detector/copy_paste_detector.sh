#!/bin/bash
# $gitbranch: Branch we are going to check
resultfilename=copy_paste_detector.xml

# calculate some variables
mydir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

# list of excluded dirs
. ${mydir}/../define_excluded/define_excluded.sh

# checkout pristine copy of the configure branch
cd ${WORKSPACE} && git checkout ${gitbranch} && git fetch && git reset --hard origin/${gitbranch}

# Create all the ant build files to specify the modules
/opt/local/bin/php ${mydir}/../generate_component_ant_files/generate_component_ant_files.php --basedir="${WORKSPACE}"

# Look for all the build.xml files, running phpcpd for them
buildxml="$( find "${WORKSPACE}" -name build.xml | sed 's/\/build.xml//g' | sort -r)"
for dir in ${buildxml}
    do
        echo "processing ${dir}"
        cd ${dir}
        /opt/local/bin/php ${mydir}/copy_paste_detector.php ${excluded_list} --quiet --log-pmd "${dir}/${resultfilename}" .
        excluded_list="${excluded_list} --exclude ${dir}"
    done

# checkout pristine copy of the configure branch
cd ${WORKSPACE} && git checkout ${gitbranch} && git fetch && git reset --hard origin/${gitbranch}

# Always return ok
exit 0
