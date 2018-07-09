create sequence UF_INVOICE_EMAIL_BATCH_LOG;

create sequence UF_INVOICE_EMAIL_LOG;

create table UF_INVOICE_EMAIL_BATCH_LOG (
    id integer,
    batchid integer,
    action varchar(200),
    info varchar(2000),
    ts timestamp
);
