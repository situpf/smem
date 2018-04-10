
Introduction
=============

SMEM is a tool originally based in the tool QMEM that was writed to work with 
SunGrid Engine. SMEM has been an adaption of the QMEM to Slurm.

SMEM show us the current state of the resources available in a Slurm cluster 
with the possibility to choose if we want to view the jobs that are allocating
the resources.

Requisites
==========

To be able to run SMEM in our cluster, this tool needs to be executed in a compute that has access to the tools sacctmgr, scontrol, sstat, sinfo and sacct.

As this tool is a php script, we also need to have php installed in the computer.

Installation
============

The only modification that is necesary to be done in the computer i to add this line in the sudo file:

ALL ALL=(ALL) NOPASSWD: /usr/bin/sstat

By default, the sstat command only allow us to show information of our jobs. We need to modify this behavior to be able to
run stat to collect any information of all the jobs that are in the cluster. This is done it using the sudo tool, so it is needed to add the previous line in the sudo file.


How to use it
==============

Description:

  Shows the resource usage of all the cluster nodes.

Usage:

  smem	[[-u] [<user-list>]] [-p <partition-name>] [-w <host-list>] [[-g] [<resources-list>]]

  -u [<user-list>]     Shows the resources in use by all the jobs currently
                         running.  If the user list is present, shows only the
                         running jobs that belong to the users list. The resource
                         list has to be comma separated list.
  -p <partition-list>      Shows information only for the given partitions The partitions has to be.
									a comma separated list.
  -g [<resource-list>] Show the usage of the general resources. If the resource
                         list is  present, shows only the usage of the resources
                         that are in the list. The resource list has to be comma
                         separated list.
  -w <host-list>       Show information only for the given host list. The host list
                         has to be a comma separated list.
  -h                   Shows this help.

