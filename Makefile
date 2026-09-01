# Makefile for github.com:TurtleEngr/WP-make-core

# ----------
# Macros

SHELL := /bin/bash

mProj = WP-make-core
mProduct = dist/make-core-VERSION.zip

mBuildList = \
    dist/make-core \
    dist/make-core/make-core.php \
    dist/make-core/readme.txt \
    dist/make-core/LICENSE

mServer = moria.whyayh.com
mPubDev = /rel/development/software/own/$(mProj)
mPubRel = /rel/released/software/own/$(mProj)

# --------------------

usage :
	@echo "update - get latest version from github"
	@echo "build - $(mProduct)"
	@echo "incPatch, incMinor, or incMajor - before save or publish"
	@echo "    Update Changelog in readme.txt"
	@echo "save - ci, push develop to github, copy to $(mPubDev)"
	@echo "publish - tag, ci, push to develop, merge to main,"
	@echo "    push to main, copy to $(mPubRel)"

update :
	git co develop
	git pull origin develop

build : dist-clean update README.md $(mProduct)
	@echo 'If OK, make save'

save development : check-dev
	-git ci -am Updated
	git push origin develop
	-ssh $(mServer) mkdir -p $(mPubDev)
	rsync -a README.org readme.txt dist/make-core-$$(cat VERSION).zip $(mServer):$(mPubDev)
	cp VERSION VERSION-dev
	git ci -am Updated
	git push origin develop
	@echo 'If OK, make publish'

publish release : check-rel
	git tag -f "ver-$$(cat VERSION)"
	git push --tags origin develop
	git co main
	git pull --tags origin main
	git merge develop
	git push --tags origin main
	git co develop
	-ssh $(mServer) mkdir -p $(mPubRel)
	rsync -a README.org readme.txt dist/make-core-$$(cat VERSION).zip $(mServer):$(mPubRel)
	cp VERSION VERSION-rel
	git ci -am Updated
	git push origin develop
	@echo 'If done, make dist-clean'

clean :
	-find . -type f -name '*~' -exec rm {} \;

dist-clean : clean
	rm -rf dist


# To remove tags: local and remote
# git tag -d v2.1.1
# git push origin --delete v2.1.1

# --------------------
# Work Targets

$(mProduct) : $(mBuildList)
	php -l make-core.php
	cd dist; zip -r make-core-$$(cat ../VERSION).zip make-core
	-touch $@

README.md : README.org VERSION
	pandoc -f org -t markdown <README.org >$@
	sed -i "s/VERSION/$$(cat VERSION)/" $@
	sed -i 's/^\[version]/![version]/' $@
	sed -i 's/^\[WordPress]/![WordPress]/' $@

check-dev :
	if diff -q VERSION VERSION-dev >/dev/null 2>&1; then \
		echo "Development versions must be different."; \
		echo "increment and rebuild."; \
		exit 1; \
	fi

check-rel :
	if diff -q VERSION VERSION-rel >/dev/null 2>&1; then \
		echo "Released versions must be different."; \
		echo "increment and rebuild."; \
		exit 1; \
	fi

# --------------------
# Single Targets

VERSION :
	echo '0.0.0' >$@

incPatch : VERSION
	incver.sh -p

incMinor : VERSION
	incver.sh -m

incMajor : VERSION
	incver.sh -M

dist/make-core :
	mkdir -p $@

dist/make-core/make-core.php : VERSION make-core.php
	sed "s/VERSION/$$(cat VERSION)/" <make-core.php >$@

dist/make-core/readme.txt : VERSION readme.txt
	sed "s/VERSION/$$(cat VERSION)/" <$? >$@

dist/make-core/LICENSE : LICENSE
	-cp $? $@
